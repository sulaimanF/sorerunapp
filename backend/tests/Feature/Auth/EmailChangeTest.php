<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\ConfirmEmailChange;
use App\Actions\Auth\RequestEmailChange;
use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Auth\ResetMemberPassword;
use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Models\AuditLog;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Services\Auth\EmailChangeNotification;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class EmailChangeTest extends TestCase
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

    private function verifiedMember(): User
    {
        $user = $this->member();
        app(VerifyEmailAndActivateMembership::class)->handle(
            (int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version,
            (string) Str::uuid(), $user->auth_version, publicProof: true,
        );

        return $user->refresh();
    }

    private function proof(User $user): array
    {
        $delivery = $user->notificationDeliveries()->where('type', 'email_change')->latest('id')->firstOrFail();
        $payload = $delivery->payload_encrypted;
        $change = EmailChangeRequest::query()->findOrFail($payload['request_id']);

        return ['request_id' => $change->id, 'email' => $change->new_email, 'token' => $payload['token']];
    }

    public function test_request_keeps_old_email_then_confirmation_changes_only_account_identity_and_revokes_session(): void
    {
        $user = $this->verifiedMember();
        $oldEmail = $user->email;
        $membership = $user->membership->getRawOriginal();
        $credentials = DB::table('member_credentials')->where('membership_id', $user->membership->id)->get()->toJson();
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('POST', '/api/v1/me/email-change', ['email' => '  NEW-MEMBER@example.test ', 'password' => self::PASSWORD])
            ->assertStatus(202)->assertHeader('Retry-After', '60')->assertJsonPath('data.result', 'email_change_requested');
        self::assertSame($oldEmail, $user->fresh()->email);
        $this->requestJson('GET', '/api/v1/me/profile')->assertOk()
            ->assertJsonPath('data.email_change.active_email', $oldEmail)
            ->assertJsonPath('data.email_change.pending.email', 'new-member@example.test');
        $proof = $this->proof($user);
        self::assertNotSame($proof['token'], EmailChangeRequest::query()->findOrFail($proof['request_id'])->token_hash);
        self::assertStringNotContainsString($proof['token'], (string) DB::table('notification_deliveries')->where('user_id', $user->id)->where('type', 'email_change')->value('payload_encrypted'));
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $proof)->assertOk()->assertJsonPath('data.result', 'email_changed');
        self::assertSame('new-member@example.test', $user->fresh()->email);
        self::assertSame($user->auth_version + 1, $user->fresh()->auth_version);
        self::assertNotNull($user->fresh()->email_verified_at);
        self::assertSame($membership, $user->membership->fresh()->getRawOriginal());
        self::assertSame($credentials, DB::table('member_credentials')->where('membership_id', $user->membership->id)->get()->toJson());
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        $this->login($user)->assertStatus(401);
        $this->login($user->fresh())->assertOk();
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $proof)->assertStatus(422)->assertJsonPath('error.code', 'EMAIL_CHANGE_INVALID');
    }

    public function test_password_csrf_extra_fields_and_cooldown_are_enforced_without_replacing_pending(): void
    {
        $user = $this->verifiedMember();
        $this->csrf();
        $this->login($user)->assertOk();
        $payload = ['email' => 'new-member@example.test', 'password' => self::PASSWORD];
        $this->requestJson('POST', '/api/v1/me/email-change', $payload, false)->assertStatus(419);
        $this->requestJson('POST', '/api/v1/me/email-change', [...$payload, 'user_id' => 999])->assertStatus(422);
        $this->requestJson('POST', '/api/v1/me/email-change', [...$payload, 'password' => 'wrong-password'])->assertStatus(422);
        self::assertSame(0, EmailChangeRequest::query()->where('user_id', $user->id)->count());
        Cache::store('array')->flush();
        $this->requestJson('POST', '/api/v1/me/email-change', $payload)->assertStatus(202);
        $proof = $this->proof($user);
        $this->requestJson('POST', '/api/v1/me/email-change', [...$payload, 'email' => 'another@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        self::assertNull(EmailChangeRequest::query()->findOrFail($proof['request_id'])->revoked_at);
    }

    public function test_other_account_cannot_confirm_and_retains_its_own_session(): void
    {
        $owner = $this->verifiedMember();
        $other = $this->verifiedMember();
        app(RequestEmailChange::class)->handle((int) $owner->id, $owner->auth_version, 'new-member@example.test', self::PASSWORD, (string) Str::uuid());
        $this->csrf();
        $this->login($other)->assertOk();
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $this->proof($owner))->assertStatus(422);
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $other->id);
        self::assertSame($owner->email, $owner->fresh()->email);
        self::assertSame($other->auth_version, $other->fresh()->auth_version);
    }

    public function test_expired_or_stale_request_cannot_be_delivered_or_confirmed(): void
    {
        $user = $this->verifiedMember();
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, 'new-member@example.test', self::PASSWORD, (string) Str::uuid());
        $proof = $this->proof($user);
        $delivery = $user->notificationDeliveries()->where('type', 'email_change')->firstOrFail();
        $change = EmailChangeRequest::query()->findOrFail($proof['request_id']);
        $change->forceFill(['expires_at' => now('UTC')->subSecond()])->save();
        self::assertNull(app(EmailChangeNotification::class)->currentRequest($delivery, $user));
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $proof)->assertStatus(422);
        self::assertNull($change->fresh()->consumed_at);
        self::assertSame($user->email, $user->fresh()->email);
        $change->forceFill(['expires_at' => now('UTC')->addHour(), 'user_version' => $user->auth_version + 1])->save();
        self::assertNull(app(EmailChangeNotification::class)->currentRequest($delivery, $user));
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $proof)->assertStatus(422);
    }

    #[DataProvider('invalidProofs')]
    public function test_invalid_proof_never_changes_account_or_consumes_request(string $reason): void
    {
        $user = $this->verifiedMember();
        $this->csrf();
        $this->login($user)->assertOk();
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, 'new-member@example.test', self::PASSWORD, (string) Str::uuid());
        $proof = $this->proof($user);
        $change = EmailChangeRequest::query()->findOrFail($proof['request_id']);
        if ($reason === 'missing') {
            unset($proof['token']);
        }
        if ($reason === 'wrong') {
            $proof['token'] = str_repeat('f', 64);
        }
        if ($reason === 'destination') {
            $proof['email'] = 'wrong@example.test';
        }
        if ($reason === 'revoked') {
            $change->forceFill(['revoked_at' => now('UTC')])->save();
        }
        if ($reason === 'consumed') {
            $change->forceFill(['consumed_at' => now('UTC')])->save();
        }
        $before = $change->fresh()->getRawOriginal();
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $proof)->assertStatus(422);
        self::assertSame($before, $change->fresh()->getRawOriginal());
        self::assertSame($user->email, $user->fresh()->email);
        self::assertSame($user->auth_version, $user->fresh()->auth_version);
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk();
    }

    public static function invalidProofs(): array
    {
        return array_map(static fn ($reason) => [$reason], ['missing', 'wrong', 'destination', 'revoked', 'consumed']);
    }

    public function test_replacement_and_password_reset_invalidate_previous_proofs(): void
    {
        $user = $this->verifiedMember();
        $action = app(RequestEmailChange::class);
        $action->handle((int) $user->id, $user->auth_version, 'first@example.test', self::PASSWORD, (string) Str::uuid());
        $old = $this->proof($user);
        $this->travel(61)->seconds();
        $action->handle((int) $user->id, $user->auth_version, 'second@example.test', self::PASSWORD, (string) Str::uuid());
        self::assertNotNull(EmailChangeRequest::query()->findOrFail($old['request_id'])->revoked_at);
        $new = $this->proof($user);
        self::assertNotSame($old['token'], $new['token']);
        app(RequestPasswordReset::class)->handle($user->email);
        $token = $user->notificationDeliveries()->where('type', 'password_reset')->latest('id')->firstOrFail()->payload_encrypted['token'];
        app(ResetMemberPassword::class)->handle(['email' => $user->email, 'token' => $token, 'password' => 'Replacement-password-123'], (string) Str::uuid());
        self::assertNotNull(EmailChangeRequest::query()->findOrFail($new['request_id'])->revoked_at);
        self::assertSame(0, $user->notificationDeliveries()->where('type', 'email_change')->whereIn('status', ['pending', 'processing', 'failed'])->count());
        self::assertSame($user->email, $user->fresh()->email);
    }

    public function test_rollback_restores_email_request_sessions_and_audit(): void
    {
        $user = $this->verifiedMember();
        $this->csrf();
        $this->login($user)->assertOk();
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, 'new-member@example.test', self::PASSWORD, (string) Str::uuid());
        $proof = $this->proof($user);
        $sessions = DB::table('sessions')->where('user_id', $user->id)->get()->toJson();
        AuditLog::creating(static function (AuditLog $audit): void {
            if ($audit->action === 'auth.email_changed') {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(ConfirmEmailChange::class)->handle((int) $user->id, $user->auth_version, $proof, (string) Str::uuid());
            self::fail('Expected rollback.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        self::assertSame($user->email, $user->fresh()->email);
        self::assertSame($user->auth_version, $user->fresh()->auth_version);
        self::assertNull(EmailChangeRequest::query()->findOrFail($proof['request_id'])->consumed_at);
        self::assertSame($sessions, DB::table('sessions')->where('user_id', $user->id)->get()->toJson());
        self::assertSame('pending', $user->notificationDeliveries()->where('type', 'email_change')->firstOrFail()->status->value);
    }

    public function test_rate_limits_normalized_email_and_validation_do_not_create_requests(): void
    {
        $user = $this->verifiedMember();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/me/email-change', ['email' => 'new@example.test', 'password' => self::PASSWORD])->assertStatus(401);
        $this->login($user)->assertOk();
        foreach (['invalid', str_repeat('a', 250).'@example.test'] as $email) {
            $this->requestJson('POST', '/api/v1/me/email-change', ['email' => $email, 'password' => self::PASSWORD])->assertStatus(422);
        }
        Cache::store('array')->flush();
        for ($i = 0; $i < 5; $i++) {
            $this->requestJson('POST', '/api/v1/me/email-change', ['email' => 'NEW@example.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->requestJson('POST', '/api/v1/me/email-change', ['email' => 'new@example.test', 'password' => self::PASSWORD])->assertStatus(429)->assertHeader('Retry-After');
        self::assertSame(0, EmailChangeRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_late_saved_session_is_rejected_and_blocked_account_cannot_confirm(): void
    {
        $user = $this->verifiedMember();
        $this->csrf();
        $this->login($user)->assertOk();
        $session = (array) DB::table('sessions')->where('user_id', $user->id)->first();
        $cookies = $this->browserCookies;
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, 'new-member@example.test', self::PASSWORD, (string) Str::uuid());
        $proof = $this->proof($user);
        $user->forceFill(['status' => 'blocked'])->save();
        $this->requestJson('POST', '/api/v1/me/email-change/verify', $proof)->assertStatus(403);
        self::assertNull(EmailChangeRequest::query()->findOrFail($proof['request_id'])->consumed_at);
        $user->forceFill(['status' => 'enabled'])->save();
        app(ConfirmEmailChange::class)->handle((int) $user->id, $user->auth_version, $proof, (string) Str::uuid());
        DB::table('sessions')->insert($session);
        $this->browserCookies = $cookies;
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
        self::assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    #[DataProvider('membershipStates')]
    public function test_confirmation_preserves_membership_state_and_credential(string $status): void
    {
        $user = $this->verifiedMember();
        $user->membership->forceFill(['status' => $status])->save();
        $before = $user->membership->getRawOriginal();
        $credential = $user->membership->credentials()->sole()->getRawOriginal();
        app(RequestEmailChange::class)->handle((int) $user->id, $user->auth_version, 'new-member@example.test', self::PASSWORD, (string) Str::uuid());
        app(ConfirmEmailChange::class)->handle((int) $user->id, $user->auth_version, $this->proof($user), (string) Str::uuid());
        self::assertSame($before, $user->membership->fresh()->getRawOriginal());
        self::assertSame($credential, $user->membership->credentials()->sole()->getRawOriginal());
        self::assertSame('enabled', $user->fresh()->status->value);
    }

    public static function membershipStates(): array
    {
        return [['active'], ['suspended'], ['ended']];
    }

    public function test_existing_email_and_invalid_transport_or_proof_fields_are_rejected(): void
    {
        $user = $this->verifiedMember();
        $other = $this->verifiedMember();
        $this->csrf();
        $this->login($user)->assertOk();
        foreach ([$user->email, strtoupper($other->email)] as $email) {
            $this->requestJson('POST', '/api/v1/me/email-change', ['email' => $email, 'password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_UNAVAILABLE');
        }
        $path = '/api/v1/me/email-change';
        $this->requestJson('GET', $path)->assertStatus(405);
        $this->requestJson('POST', $path, rawBody: '{')->assertStatus(400);
        $this->requestJson('POST', $path, rawBody: '[]')->assertStatus(400);
        $this->requestJson('POST', $path, rawBody: str_repeat(' ', 4097))->assertStatus(413);
        $this->requestJson('POST', $path.'?user_id=1', ['email' => 'new-member@example.test', 'password' => self::PASSWORD])->assertStatus(422);
        $this->requestJson('POST', $path.'/verify', ['request_id' => 'bad', 'email' => 'new-member@example.test', 'token' => 'short'])->assertStatus(422);
        self::assertSame(0, EmailChangeRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_account_limit_survives_changing_ip_and_target(): void
    {
        $user = $this->verifiedMember();
        $this->csrf();
        $this->login($user)->assertOk();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i]);
            $this->requestJson('POST', '/api/v1/me/email-change', ['email' => 'new'.$i.'@example.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.6']);
        $this->requestJson('POST', '/api/v1/me/email-change', ['email' => 'other@example.test', 'password' => self::PASSWORD])->assertStatus(429)->assertHeader('Retry-After');
    }
}
