<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Auth\ResetMemberPassword;
use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use VerificationBrowser, VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
        DB::beginTransaction();
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        Notification::fake();
        Queue::fake();
        config([
            'sorerunapp.outbox.dispatch_on_commit' => false, 'sorerunapp.membership.number_prefix' => 'TEST',
            'sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class,
        ]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function token(User $user): string
    {
        app(RequestPasswordReset::class)->handle($user->email);

        return $user->notificationDeliveries()->where('type', 'password_reset')->latest('id')->firstOrFail()->payload_encrypted['token'];
    }

    private function payload(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'New-test-password-123', 'password_confirmation' => 'New-test-password-123'];
    }

    private function activate(User $user): void
    {
        app(VerifyEmailAndActivateMembership::class)->handle((int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version, (string) Str::uuid(), $user->auth_version);
        $user->refresh();
    }

    #[DataProvider('accountStates')]
    public function test_forgot_response_is_uniform_and_stores_only_hash_and_encrypted_outbox(string $status, bool $verified): void
    {
        $user = $this->member();
        $user->forceFill(['status' => $status, 'email_verified_at' => $verified ? now('UTC') : null])->save();
        $this->csrf();
        $known = $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => '  '.strtoupper($user->email).'  '])
            ->assertStatus(202)->assertHeader('Retry-After', '60')->assertHeader('Cache-Control', 'no-store, private');
        $unknown = $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => 'absent@example.test'])->assertStatus(202)->assertHeader('Retry-After', '60');
        $cooldown = $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202)->assertHeader('Retry-After', '60');
        self::assertSame($known->json('data'), $unknown->json('data'));
        self::assertSame($known->json('data'), $cooldown->json('data'));
        self::assertSame(['data', 'meta'], array_keys($known->json()));
        self::assertSame(['request_id'], array_keys($known->json('meta')));
        $row = $user->notificationDeliveries()->where('type', 'password_reset')->sole();
        $token = $row->payload_encrypted['token'];
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        self::assertTrue(Hash::check($token, $stored->token));
        self::assertSame((int) $user->id, (int) $stored->user_id);
        self::assertStringNotContainsString($token, $row->getRawOriginal('payload_encrypted'));
        self::assertArrayNotHasKey('payload_encrypted', $row->toArray());
        Notification::assertNothingSent();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public static function accountStates(): array
    {
        return [['enabled', true], ['enabled', false], ['blocked', true], ['blocked', false]];
    }

    public function test_cooldown_prevents_issuance_then_new_request_replaces_old_token(): void
    {
        $user = $this->member();
        $old = $this->token($user);
        self::assertSame($old, $this->token($user));
        $this->travel(61)->seconds();
        $new = $this->token($user);
        self::assertNotSame($old, $new);
        self::assertSame(2, $user->notificationDeliveries()->where('type', 'password_reset')->count());
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($user, $old))->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
    }

    #[DataProvider('endpoints')]
    public function test_csrf_stateful_json_validation_and_get_are_enforced(string $endpoint): void
    {
        $user = $this->member();
        $payload = $endpoint === 'forgot-password' ? ['email' => $user->email] : $this->payload($user, str_repeat('a', 64));
        $path = '/api/v1/auth/'.$endpoint;
        $this->requestJson('POST', $path, $payload, false)->assertStatus(419);
        $this->csrf();
        $this->requestJson('POST', $path, $payload, true, ['Origin' => 'https://untrusted.example', 'Referer' => 'https://untrusted.example/'])->assertStatus(419);
        $this->requestJson('POST', $path, $payload, true, ['Content-Type' => 'text/plain'], '{}')->assertStatus(415);
        $this->requestJson('POST', $path, [], true, [], '[')->assertStatus(400);
        $this->requestJson('POST', $path, [...$payload, 'redirect' => 'https://untrusted.example'])->assertStatus(422);
        $this->requestJson('POST', $path.'?email=bad@example.test', $payload)->assertStatus(422);
        $this->requestJson('POST', $path, [...$payload, 'email' => 'invalid'])->assertStatus(422)->assertJsonValidationErrors('email', 'error.fields');
        $this->requestJson('GET', $path)->assertStatus(405);
        self::assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
    }

    public static function endpoints(): array
    {
        return [['forgot-password'], ['reset-password']];
    }

    #[DataProvider('endpoints')]
    public function test_rate_limit_is_independent_of_account_lookup(string $endpoint): void
    {
        $this->csrf();
        for ($i = 0; $i < 5; $i++) {
            $data = ['email' => 'absent@example.test'];
            if ($endpoint === 'reset-password') {
                $data += ['token' => str_repeat('a', 64), 'password' => 'New-test-password-123', 'password_confirmation' => 'New-test-password-123'];
            }
            $this->requestJson('POST', '/api/v1/auth/'.$endpoint, $data)->assertStatus($endpoint === 'forgot-password' ? 202 : 422);
        }
        $this->requestJson('POST', '/api/v1/auth/'.$endpoint, $data)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_email_limit_survives_changing_ip_and_ip_limit_survives_changing_email(): void
    {
        $this->csrf();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => 'absent@example.test'])->assertStatus(202);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.6']);
        $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => 'ABSENT@example.test'])->assertStatus(429);
        Cache::store('array')->flush();
        for ($i = 1; $i <= 5; $i++) {
            $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => 'absent'.$i.'@example.test'])->assertStatus(202);
        }
        $this->requestJson('POST', '/api/v1/auth/forgot-password', ['email' => 'another@example.test'])->assertStatus(429);
    }

    public function test_reset_changes_password_consumes_token_and_rejects_late_old_session(): void
    {
        $user = $this->member();
        $this->activate($user);
        $beforeMembership = $user->membership->fresh()->getAttributes();
        $beforeCredential = $user->membership->credentials()->sole()->getAttributes();
        $this->csrf();
        $this->login($user)->assertOk();
        $oldCookies = $this->browserCookies;
        $session = (array) DB::table('sessions')->where('user_id', $user->id)->first();
        $token = $this->token($user);
        $this->browserCookies = [];
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($user, $token))->assertOk()->assertJsonPath('data.result', 'password_reset');
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertTrue(Hash::check('New-test-password-123', $user->fresh()->password));
        self::assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        self::assertSame($beforeMembership, $user->membership->fresh()->getAttributes());
        self::assertSame($beforeCredential, $user->membership->credentials()->sole()->getAttributes());
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        // Simulate a late writer that has already refreshed Sanctum's password hash,
        // but still carries the old application auth_version.
        $sessionData = json_decode(base64_decode($session['payload']), true, flags: JSON_THROW_ON_ERROR);
        $sessionData['password_hash_web'] = $user->fresh()->password;
        $session['payload'] = base64_encode(json_encode($sessionData, JSON_THROW_ON_ERROR));
        DB::table('sessions')->insert($session);
        $this->browserCookies = $oldCookies;
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
        $this->login($user)->assertStatus(401);
        $this->login($user, 'New-test-password-123')->assertOk();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($user, $token))->assertStatus(422);
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertSame(1, AuditLog::query()->where('action', 'auth.password_reset')->where('target_id', $user->id)->count());
    }

    #[DataProvider('accountStates')]
    public function test_reset_does_not_change_verification_block_or_membership(string $status, bool $verified): void
    {
        $user = $this->member();
        $user->forceFill(['status' => $status, 'email_verified_at' => $verified ? now('UTC') : null])->save();
        $membership = $user->membership->getAttributes();
        $verifiedAt = $user->email_verified_at;
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($user, $this->token($user)))->assertOk();
        self::assertSame($status, $user->fresh()->status->value);
        self::assertEquals($verifiedAt, $user->fresh()->email_verified_at);
        self::assertSame($membership, $user->membership->fresh()->getAttributes());
        self::assertSame(0, $user->membership->credentials()->count());
        $this->login($user, 'New-test-password-123')->assertStatus($status === 'blocked' ? 401 : ($verified ? 200 : 403));
    }

    #[DataProvider('invalidProofs')]
    public function test_invalid_or_stale_proof_has_no_partial_writes(string $mode): void
    {
        $user = $this->member();
        $token = $this->token($user);
        $payload = $this->payload($user, $token);
        if ($mode === 'invalid') {
            $payload['token'] = str_repeat('a', 64);
        }
        if ($mode === 'expired') {
            DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        }
        if ($mode === 'email') {
            $user->forceFill(['email' => 'changed@example.test'])->save();
        }
        if ($mode === 'version') {
            $user->forceFill(['auth_version' => 2, 'status' => 'blocked'])->save();
        }
        if ($mode === 'owner') {
            DB::table('password_reset_tokens')->where('email', $user->email)->update(['user_id' => $this->member()->id]);
        }
        $before = $user->fresh()->getAttributes();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $payload)->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');
        self::assertSame($before, $user->fresh()->getAttributes());
        self::assertSame(0, AuditLog::query()->where('action', 'auth.password_reset')->where('target_id', $user->id)->count());
    }

    public static function invalidProofs(): array
    {
        return [['invalid'], ['expired'], ['email'], ['version'], ['owner']];
    }

    #[DataProvider('invalidPasswords')]
    public function test_password_validation_does_not_consume_the_token(string $password, string $confirmation): void
    {
        $user = $this->member();
        $token = $this->token($user);
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/reset-password', [...$this->payload($user, $token), 'password' => $password, 'password_confirmation' => $confirmation])->assertStatus(422);
        self::assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        self::assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        self::assertSame(1, $user->fresh()->auth_version);
    }

    public static function invalidPasswords(): array
    {
        return [['short', 'short'], ['Long-password-123', 'different'], [str_repeat('é', 37), str_repeat('é', 37)], ["Long-password-\0", "Long-password-\0"]];
    }

    public function test_reset_keeps_another_logged_in_identity(): void
    {
        $subject = $this->member();
        $other = $this->member();
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->csrf();
        $this->login($other)->assertOk();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($subject, $this->token($subject)))->assertOk();
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $other->id);
        self::assertSame(1, $other->fresh()->auth_version);
    }

    public function test_failure_rolls_back_password_token_sessions_and_audit(): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $this->csrf();
        $this->login($user)->assertOk();
        $sessions = DB::table('sessions')->where('user_id', $user->id)->get()->all();
        self::assertNotEmpty($sessions);
        $token = $this->token($user);
        $before = $user->fresh()->getAttributes();
        AuditLog::saving(static function (): void {
            throw new RuntimeException('Synthetic reset rollback.');
        });
        try {
            app(ResetMemberPassword::class)->handle($this->payload($user, $token), (string) Str::uuid());
            self::fail('Expected synthetic failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic reset rollback.', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        self::assertSame($before, $user->fresh()->getAttributes());
        self::assertEquals($sessions, DB::table('sessions')->where('user_id', $user->id)->get()->all());
        self::assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        self::assertSame('pending', $user->notificationDeliveries()->where('type', 'password_reset')->sole()->status->value);
    }

    public function test_reset_from_its_own_authenticated_session_requires_login_again(): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($user, $this->token($user)))->assertOk();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        $this->csrf();
        $this->login($user, 'New-test-password-123')->assertOk();
    }

    #[DataProvider('inactiveMemberships')]
    public function test_reset_preserves_inactive_membership_number_and_credential(string $status): void
    {
        $user = $this->member();
        $this->activate($user);
        $user->membership->forceFill(['status' => $status])->save();
        $membership = $user->membership->fresh()->getAttributes();
        $credential = $user->membership->credentials()->sole()->getAttributes();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/reset-password', $this->payload($user, $this->token($user)))->assertOk();
        self::assertSame($membership, $user->membership->fresh()->getAttributes());
        self::assertSame($credential, $user->membership->credentials()->sole()->getAttributes());
    }

    public static function inactiveMemberships(): array
    {
        return [['suspended'], ['ended']];
    }
}
