<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Memberships\GetMembershipCard;
use App\Actions\Memberships\IssueMemberCredential;
use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Actions\Notifications\RecordNotificationDelivery;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\MemberNumberSequence;
use App\Models\User;
use App\Services\Auth\EmailVerificationUrl;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use VerificationBrowser, VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PreventRequestForgery::class, EnforceCsrfInRegistrationTests::class);
        $this->requireIsolatedMysql();
        DB::beginTransaction();
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        Queue::fake();
        Notification::fake();
        config([
            'app.url' => 'http://localhost:8000', 'sorerunapp.membership.number_prefix' => 'TEST',
            'sorerunapp.outbox.dispatch_on_commit' => false,
            'sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function link(User $user): string
    {
        $url = app(EmailVerificationUrl::class)->forUser($user);

        return (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    private function activate(User $user): array
    {
        return app(VerifyEmailAndActivateMembership::class)->handle((int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version, (string) Str::uuid(), $user->auth_version);
    }

    public function test_guest_verifies_explicitly_then_logs_in_and_gets_one_stable_private_card(): void
    {
        $user = $this->member();
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $link = $this->link($user);
        $this->requestJson('GET', $link)->assertOk()->assertJsonPath('data.result', 'verification_link_ready');
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(0, $user->membership->credentials()->count());
        $this->csrf();
        $this->login($user)->assertStatus(403)->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        $this->requestJson('POST', $link, [], false)->assertStatus(419);
        $verified = $this->requestJson('POST', $link)->assertOk()->assertJsonPath('data.result', 'email_verified');
        self::assertSame(['result'], array_keys($verified->json('data')));
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        self::assertNotNull($user->fresh()->email_verified_at);
        $number = 'TEST-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        self::assertSame($number, $user->membership->fresh()->member_number);
        $credential = $user->membership->credentials()->sole();
        self::assertNull($credential->issued_by);
        self::assertSame(hash('sha256', $credential->token_encrypted, true), $credential->token_hash);
        self::assertNotSame($credential->token_encrypted, $credential->getRawOriginal('token_encrypted'));
        self::assertFalse(DB::table('audit_logs')->where('target_id', $user->membership->id)->whereNotNull('actor_user_id')->exists());
        $this->login($user->fresh())->assertOk();
        $card = $this->requestJson('GET', '/api/v1/me/membership/card')->assertOk()->assertJsonPath('data.member_number', $number);
        self::assertSame('src-member:v1:'.$credential->token_encrypted, $card->json('data.qr_payload'));
        $this->requestJson('POST', $link)->assertOk()->assertJsonPath('data.result', 'already_verified');
        $again = $this->requestJson('GET', '/api/v1/me/membership/card')->assertOk();
        self::assertSame($card->json('data'), $again->json('data'));
        self::assertSame(1, $user->membership->credentials()->count());
        self::assertSame($next + 1, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(1, $user->notificationDeliveries()->where('type', 'membership_activated')->count());
        self::assertStringNotContainsString($credential->token_encrypted, $verified->getContent());
    }

    public function test_signed_proof_verifies_its_subject_without_switching_an_existing_account(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $this->activate($other);
        $this->csrf();
        $this->login($other->fresh())->assertOk();
        $this->requestJson('POST', $this->link($owner))->assertOk()->assertJsonPath('data.result', 'email_verified');
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $other->id);
        $this->requestJson('GET', '/api/v1/me/membership/card')->assertOk()->assertJsonPath('data.member_number', $other->membership->fresh()->member_number);
        self::assertNotSame($owner->membership->fresh()->member_number, $other->membership->fresh()->member_number);
    }

    public function test_expired_tampered_and_stale_links_do_not_activate(): void
    {
        $user = $this->member();
        $link = $this->link($user);
        $this->csrf();
        $this->requestJson('POST', $link.'&unexpected=1')->assertStatus(403)->assertJsonPath('error.code', 'INVALID_VERIFICATION_LINK');
        $expired = URL::temporarySignedRoute('api.v1.auth.email-verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email), 'v' => $user->auth_version]);
        $this->requestJson('POST', parse_url($expired, PHP_URL_PATH).'?'.parse_url($expired, PHP_URL_QUERY))->assertStatus(403);
        $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
        $this->requestJson('POST', $link)->assertStatus(403)->assertJsonPath('error.code', 'VERIFICATION_LINK_STALE');
        self::assertNull($user->fresh()->email_verified_at);
    }

    public function test_array_signature_is_rejected_as_403_instead_of_a_server_error(): void
    {
        $user = $this->member();
        $this->csrf();
        $link = $this->link($user);
        $this->requestJson('POST', str_replace('signature=', 'signature%5B%5D=', $link))->assertStatus(403)->assertJsonPath('error.code', 'INVALID_VERIFICATION_LINK');
        self::assertNull($user->fresh()->email_verified_at);
    }

    public function test_locked_user_version_is_rechecked_inside_sensitive_actions(): void
    {
        $user = $this->member();
        $actions = [
            fn () => app(VerifyEmailAndActivateMembership::class)->handle((int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version, (string) Str::uuid(), 0),
        ];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail('A stale session version must be rejected.');
            } catch (ApiProblem $exception) {
                self::assertSame('SESSION_EXPIRED', $exception->errorCode);
                self::assertSame(401, $exception->status);
            }
        }
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(0, $user->notificationDeliveries()->count());
        $this->activate($user);
        try {
            app(GetMembershipCard::class)->handle((int) $user->id, 0);
            self::fail('A stale session must not read the private card.');
        } catch (ApiProblem $exception) {
            self::assertSame('SESSION_EXPIRED', $exception->errorCode);
        }
    }

    public function test_email_change_invalidates_old_signed_link(): void
    {
        $user = $this->member();
        $link = $this->link($user);
        $user->email = 'changed-'.bin2hex(random_bytes(8)).'@example.test';
        $user->save();
        $this->csrf();
        $this->requestJson('POST', $link)->assertStatus(403)->assertJsonPath('error.code', 'VERIFICATION_LINK_STALE');
        self::assertNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('inactiveStatuses')]
    public function test_verification_never_reactivates_inactive_membership(string $status): void
    {
        $user = $this->member();
        $user->membership->forceFill(['status' => $status, 'member_number' => 'TEST-'.bin2hex(random_bytes(8)), 'joined_at' => now('UTC')])->save();
        $this->csrf();
        $this->requestJson('POST', $this->link($user))->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_NOT_ACTIVATABLE');
        self::assertSame($status, $user->membership->fresh()->status->value);
        self::assertNull($user->fresh()->email_verified_at);
    }

    public static function inactiveStatuses(): array
    {
        return [['suspended'], ['ended']];
    }

    public function test_blocked_account_cannot_verify_or_read_card(): void
    {
        $user = $this->member();
        $this->csrf();
        $user->forceFill(['status' => 'blocked', 'blocked_at' => now('UTC')])->save();
        $this->requestJson('POST', $this->link($user))->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
        $this->requestJson('GET', '/api/v1/me/membership/card')->assertStatus(401);
    }

    public function test_incomplete_profile_verifies_email_and_waits_before_issuing_number(): void
    {
        $user = $this->member();
        $user->memberProfile()->delete();
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $this->csrf();
        $link = $this->link($user);
        $this->requestJson('POST', $link)->assertOk()->assertJsonPath('data.result', 'email_verified_profile_incomplete');
        self::assertNotNull($user->fresh()->email_verified_at);
        self::assertSame($next, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(0, $user->membership->credentials()->count());
        $user->memberProfile()->create(['full_name' => 'Anggota Uji', 'birth_date' => '2000-01-01', 'phone' => '+6281234567890', 'city' => 'Kota Uji']);
        $this->requestJson('POST', $link)->assertOk()->assertJsonPath('data.result', 'email_verified');
    }

    #[DataProvider('rollbackFailures')]
    public function test_credential_or_outbox_failure_rolls_back_entire_activation(string $service): void
    {
        $user = $this->member();
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $mock = Mockery::mock($service);
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('synthetic stage05 failure'));
        $this->app->instance($service, $mock);
        try {
            $this->activate($user);
            self::fail('Activation must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic stage05 failure', $exception->getMessage());
        }
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(MembershipStatus::PendingVerification, $user->membership->fresh()->status);
        self::assertNull($user->membership->fresh()->member_number);
        self::assertSame($next, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(0, $user->membership->credentials()->count());
        self::assertSame(0, $user->notificationDeliveries()->count());
        self::assertSame(0, DB::table('audit_logs')->where('target_id', $user->membership->id)->count());
    }

    public static function rollbackFailures(): array
    {
        return [[IssueMemberCredential::class], [RecordNotificationDelivery::class]];
    }

    public function test_missing_number_configuration_leaves_email_and_membership_unchanged(): void
    {
        $user = $this->member();
        config(['sorerunapp.membership.number_prefix' => null]);
        $this->csrf();
        $this->requestJson('POST', $this->link($user))->assertStatus(503);
        self::assertNull($user->fresh()->email_verified_at);
        self::assertNull($user->membership->fresh()->member_number);
    }

    #[DataProvider('credentialDamage')]
    public function test_unavailable_credential_is_not_regenerated(string $damage): void
    {
        $user = $this->member();
        $this->activate($user);
        $credential = $user->membership->credentials()->sole();
        $number = $user->membership->fresh()->member_number;
        if ($damage === 'ciphertext') {
            DB::table('member_credentials')->where('id', $credential->id)->update(['token_encrypted' => 'invalid-ciphertext']);
        } elseif ($damage === 'hash') {
            DB::table('member_credentials')->where('id', $credential->id)->update(['token_hash' => random_bytes(32)]);
        } else {
            $credential->forceFill(['revoked_at' => now('UTC')])->save();
        }
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('GET', '/api/v1/me/membership/card')->assertStatus(503)->assertJsonPath('error.code', 'CREDENTIAL_UNAVAILABLE');
        self::assertSame(1, $user->membership->credentials()->count());
        self::assertSame($number, $user->membership->fresh()->member_number);
    }

    public static function credentialDamage(): array
    {
        return [['ciphertext'], ['hash'], ['revoked']];
    }

    #[DataProvider('ineligibleCardStates')]
    public function test_card_rechecks_current_email_profile_and_membership_eligibility(string $state): void
    {
        $user = $this->member();
        $this->activate($user);

        // Ambil kondisi terbaru setelah aktivasi.
        $user->refresh();
        self::assertNotNull($user->email_verified_at);

        $this->csrf();
        $this->login($user)->assertOk();
        if ($state === 'email') {
            $user->forceFill(['email_verified_at' => null])->save();

            // Pastikan kondisi yang akan diuji benar-benar tersimpan.
            self::assertNull($user->fresh()->email_verified_at);
        } elseif ($state === 'profile') {
            $user->memberProfile()->delete();
        } else {
            $user->membership->forceFill(['status' => $state])->save();
        }
        $this->requestJson('GET', '/api/v1/me/membership/card')->assertStatus(403)->assertJsonPath('error.code', $state === 'email' ? 'EMAIL_NOT_VERIFIED' : 'MEMBERSHIP_CARD_UNAVAILABLE');
    }

    public static function ineligibleCardStates(): array
    {
        return [['email'], ['profile'], ['suspended'], ['ended']];
    }

    public function test_public_resend_requires_csrf_and_recipient_cooldown_is_generic(): void
    {
        $user = $this->member();
        $this->csrf();
        $path = '/api/v1/auth/email-verification/resend';
        $data = ['email' => $user->email];
        $this->requestJson('POST', $path, $data, false)->assertStatus(419);
        $this->requestJson('POST', $path, $data + ['user_id' => 1])->assertStatus(422);
        $first = $this->requestJson('POST', $path, $data)->assertStatus(202)->assertHeader('Retry-After', '60');
        $again = $this->requestJson('POST', $path, $data)->assertStatus(202)->assertHeader('Retry-After', '60');
        self::assertSame($first->json('data'), $again->json('data'));
        self::assertSame(1, $user->notificationDeliveries()->count());
        $unknown = $this->requestJson('POST', $path, ['email' => 'unknown@example.test'])->assertStatus(202);
        self::assertSame($first->json('data'), $unknown->json('data'));
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        DB::table('notification_deliveries')->where('user_id', $user->id)->update(['created_at' => now('UTC')->subSeconds(61)]);
        $this->requestJson('POST', $path, $data)->assertStatus(202);
        self::assertSame(2, $user->notificationDeliveries()->count());
    }

    public function test_public_resend_does_not_reveal_verified_or_blocked_accounts(): void
    {
        $active = $this->member();
        $this->activate($active);
        $blocked = $this->member();
        $blocked->forceFill(['status' => 'blocked', 'blocked_at' => now('UTC')])->save();
        $this->csrf();
        $before = DB::table('notification_deliveries')->count();
        $responses = [];
        foreach ([$active->email, $blocked->email, 'absent@example.test'] as $email) {
            $responses[] = $this->requestJson('POST', '/api/v1/auth/email-verification/resend', ['email' => $email])->assertStatus(202)->assertHeader('Retry-After', '60')->json('data');
        }
        self::assertSame($responses[0], $responses[1]);
        self::assertSame($responses[0], $responses[2]);
        self::assertSame($before, DB::table('notification_deliveries')->count());
    }

    public function test_public_proof_rejects_client_identity_and_invalid_json_without_writes(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->requestJson('POST', $this->link($user), ['user_id' => $user->id])->assertStatus(422);
        $this->requestJson('POST', $this->link($user), [], true, ['Content-Type' => 'text/plain'])->assertStatus(415);
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(0, $user->membership->credentials()->count());
    }

    public function test_public_resend_validates_email_and_limits_unknown_addresses_too(): void
    {
        $this->csrf();
        $path = '/api/v1/auth/email-verification/resend';
        $this->requestJson('POST', $path, ['email' => 'invalid'])->assertStatus(422);
        $this->requestJson('POST', $path)->assertStatus(422);
        Cache::store('array')->flush();
        for ($i = 0; $i < 5; $i++) {
            $this->requestJson('POST', $path, ['email' => 'absent@example.test'])->assertStatus(202);
        }
        $this->requestJson('POST', $path, ['email' => 'absent@example.test'])->assertStatus(429)->assertHeader('Retry-After');
    }

    #[DataProvider('invalidProofBodies')]
    public function test_public_proof_rejects_malformed_nonobject_deep_and_large_json(string $body, int $status): void
    {
        $user = $this->member();
        $this->csrf();
        $this->requestJson('POST', $this->link($user), rawBody: $body)->assertStatus($status);
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(0, $user->membership->credentials()->count());
    }

    public static function invalidProofBodies(): array
    {
        return [
            ['{', 400], ['[]', 400], ['true', 400],
            [str_repeat(' ', 4097).'{}', 413],
            ['{"a":'.str_repeat('[', 9).'0'.str_repeat(']', 9).'}', 400],
        ];
    }

    public function test_verification_retires_an_old_unverified_session_instead_of_turning_it_into_a_login(): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $this->csrf();
        $this->login($user)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->requestJson('POST', $this->link($user))->assertOk()->assertJsonPath('data.result', 'email_verified');
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
    }
}
