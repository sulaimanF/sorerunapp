<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Actions\Members\GetMemberProfile;
use App\Actions\Members\UpdateMemberProfile;
use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Actions\Notifications\RecordNotificationDelivery;
use App\Data\Members\UpdateMemberProfileData;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\MemberNumberSequence;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

final class MemberProfileTest extends TestCase
{
    use VerificationBrowser, VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function payload(): array
    {
        return ['full_name' => 'Anggota Profil Revisi', 'birth_date' => '2012-02-29', 'phone' => '081234567890', 'city' => 'Jakarta Uji', 'gender' => 'Tidak menyebutkan'];
    }

    private function signedIn(User $user): void
    {
        $this->csrf();
        $this->login($user)->assertOk();
    }

    public function test_profile_is_private_owned_whitelisted_and_no_store(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $other->memberProfile->forceFill(['full_name' => 'Profil Orang Lain'])->save();
        $this->signedIn($owner);
        $response = $this->requestJson('GET', '/api/v1/me/profile?user_id='.$other->id)->assertOk();
        $response->assertJsonPath('data.full_name', $owner->memberProfile->full_name)
            ->assertJsonPath('data.birth_date', '2000-01-01');
        self::assertStringNotContainsString('Profil Orang Lain', $response->getContent());
        self::assertSame(['full_name', 'birth_date', 'phone', 'city', 'gender', 'profile_complete', 'updated_at'], array_keys($response->json('data')));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-ID'));
        self::assertFalse(Gate::forUser($other)->allows('update', $owner->memberProfile));
        $this->requestJson('GET', '/api/v1/me/profile/'.$other->memberProfile->id)->assertNotFound();
    }

    public function test_update_normalizes_data_accepts_a_minor_and_audits_only_field_names(): void
    {
        $user = $this->member();
        $this->signedIn($user);
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload())
            ->assertOk()->assertJsonPath('data.full_name', 'Anggota Profil Revisi')
            ->assertJsonPath('data.birth_date', '2012-02-29')->assertJsonPath('data.phone', '+6281234567890')
            ->assertJsonPath('data.profile_complete', true);
        self::assertSame('Anggota Profil Revisi', $user->fresh()->name);
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(MembershipStatus::PendingVerification, $user->membership->fresh()->status);
        self::assertSame(0, $user->membership->credentials()->count());
        $audit = AuditLog::query()->where('action', 'member.profile_updated')->where('actor_user_id', $user->id)->sole();
        self::assertSame((int) $user->id, (int) $audit->actor_user_id);
        self::assertSame('member_profile', $audit->target_type);
        self::assertSame((int) $user->memberProfile->id, (int) $audit->target_id);
        self::assertNull($audit->before);
        self::assertArrayHasKey('changed_fields', $audit->after);
        $logged = json_encode($audit->after, JSON_THROW_ON_ERROR);
        foreach (['Anggota Profil Revisi', '2012-02-29', '+6281234567890', 'Jakarta Uji', $user->email] as $value) {
            self::assertStringNotContainsString($value, $logged);
        }
        $this->requestJson('GET', '/api/v1/auth/me')->assertJsonPath('data.user.full_name', 'Anggota Profil Revisi');
    }

    public function test_partial_update_preserves_omitted_fields_and_gender_can_be_cleared(): void
    {
        $user = $this->member();
        $user->memberProfile->forceFill(['gender' => 'Opsional'])->save();
        $this->signedIn($user);
        $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => '  Bandung Uji  ', 'gender' => null])
            ->assertOk()->assertJsonPath('data.city', 'Bandung Uji')->assertJsonPath('data.gender', null)
            ->assertJsonPath('data.full_name', $user->memberProfile->full_name)->assertJsonPath('data.birth_date', '2000-01-01');
    }

    public function test_identical_retry_does_not_create_another_profile_audit(): void
    {
        $user = $this->member();
        $this->signedIn($user);
        $first = $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => 'Bandung Uji'])->assertOk();
        $second = $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => 'Bandung Uji'])->assertOk();
        self::assertSame($first->json('data'), $second->json('data'));
        self::assertSame(1, AuditLog::query()->where('action', 'member.profile_updated')->where('actor_user_id', $user->id)->count());
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_fields_are_rejected_without_partial_writes(string $field, mixed $value): void
    {
        $user = $this->member();
        $this->signedIn($user);
        $before = $user->memberProfile->getAttributes();
        $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => 'Tidak boleh tersimpan', $field => $value])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame($before, $user->memberProfile->fresh()->getAttributes());
        self::assertSame(0, AuditLog::query()->where('action', 'member.profile_updated')->where('actor_user_id', $user->id)->count());
    }

    public static function invalidChanges(): array
    {
        return [
            ['full_name', ' '], ['full_name', []], ['full_name', 'A'], ['full_name', str_repeat('a', 151)],
            ['birth_date', null], ['birth_date', '2011-02-29'], ['birth_date', '2999-01-01'], ['birth_date', '01/01/2000'],
            ['phone', 'invalid'], ['phone', null], ['city', ' '], ['city', str_repeat('a', 101)],
            ['gender', str_repeat('a', 33)], ['gender', []], ['user_id', 999], ['email', 'other@example.test'],
            ['status', 'active'], ['roles', ['admin']], ['photo_path', 'private/foreign.jpg'], ['auth_version', 999],
            ['member_number', 'TEST-999999'], ['password', 'not-supported'],
        ];
    }

    public function test_patch_without_editable_fields_is_rejected(): void
    {
        $this->signedIn($this->member());
        $this->requestJson('PATCH', '/api/v1/me/profile', ['unsupported' => true])->assertUnprocessable();
    }

    public function test_transport_rejects_malformed_nonobject_and_oversized_json(): void
    {
        foreach (['{"invalid":', '"scalar"', '[1,2]'] as $body) {
            $this->call('PATCH', 'http://localhost:8000/api/v1/me/profile', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
                ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        }
        $this->call('PATCH', 'http://localhost:8000/api/v1/me/profile', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], 'city=invalid')
            ->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        $this->call('PATCH', 'http://localhost:8000/api/v1/me/profile', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['city' => str_repeat('a', 17000)]))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_guest_missing_csrf_and_non_browser_requests_cannot_update(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->requestJson('GET', '/api/v1/me/profile')->assertUnauthorized();
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload())->assertUnauthorized();
        $this->login($user)->assertOk();
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload(), false)->assertStatus(419);
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload(), true, ['Origin' => '', 'Referer' => ''])->assertUnauthorized();
        self::assertSame('Kota Uji', $user->memberProfile->fresh()->city);
    }

    public function test_block_and_revoked_session_are_checked_again(): void
    {
        $user = $this->member();
        $this->signedIn($user);
        $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload())->assertUnauthorized();
        $this->csrf();
        $this->login($user)->assertOk();
        $user->forceFill(['status' => AccountStatus::Blocked])->save();
        $this->requestJson('GET', '/api/v1/me/profile')->assertForbidden();
        self::assertSame('Kota Uji', $user->memberProfile->fresh()->city);
    }

    public function test_locked_actions_reject_an_old_session_version(): void
    {
        $user = $this->member();
        foreach ([
            fn () => app(GetMemberProfile::class)->handle((int) $user->id, 0),
            fn () => app(UpdateMemberProfile::class)->handle((int) $user->id, 0, new UpdateMemberProfileData(['city' => 'Baru']), (string) Str::uuid()),
        ] as $operation) {
            try {
                $operation();
                self::fail('Old session version must be rejected.');
            } catch (ApiProblem $exception) {
                self::assertSame('SESSION_EXPIRED', $exception->errorCode);
            }
        }
        self::assertSame('Kota Uji', $user->memberProfile->fresh()->city);
    }

    public function test_missing_profile_returns_404_without_creating_a_profile(): void
    {
        $user = $this->member();
        $user->memberProfile()->delete();
        $this->signedIn($user);
        $this->requestJson('GET', '/api/v1/me/profile')->assertNotFound();
        $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => 'Baru'])->assertNotFound();
        self::assertFalse($user->memberProfile()->exists());
    }

    public function test_verified_pending_profile_completion_activates_once_and_keeps_number_on_retry(): void
    {
        $user = $this->member();
        $user->memberProfile->forceFill(['city' => ''])->save();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $this->signedIn($user);
        $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => 'Kota Lengkap'])->assertOk();
        $number = $user->membership->fresh()->member_number;
        self::assertSame(MembershipStatus::Active, $user->membership->fresh()->status);
        self::assertNotNull($number);
        $this->requestJson('PATCH', '/api/v1/me/profile', ['city' => 'Kota Lengkap'])->assertOk();
        self::assertSame($number, $user->membership->fresh()->member_number);
        self::assertSame(1, $user->membership->credentials()->count());
        self::assertSame($next + 1, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(1, $user->notificationDeliveries()->where('type', 'membership_activated')->count());
    }

    public function test_editing_an_active_profile_preserves_qr_number_join_date_and_session_version(): void
    {
        $user = $this->member();
        app(VerifyEmailAndActivateMembership::class)->handle((int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version, (string) Str::uuid(), $user->auth_version);
        $number = $user->membership->fresh()->member_number;
        $joined = $user->membership->fresh()->joined_at;
        $credential = $user->membership->credentials()->sole()->getAttributes();
        $version = $user->auth_version;
        $this->signedIn($user);
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload())->assertOk();
        self::assertSame($number, $user->membership->fresh()->member_number);
        self::assertTrue($joined->equalTo($user->membership->fresh()->joined_at));
        self::assertSame($credential, $user->membership->credentials()->sole()->getAttributes());
        self::assertSame($version, $user->fresh()->auth_version);
        $this->requestJson('GET', '/api/v1/me/membership/card')->assertOk()->assertJsonPath('data.full_name', 'Anggota Profil Revisi');
    }

    #[DataProvider('inactiveMemberships')]
    public function test_profile_edit_never_reactivates_suspended_or_ended(string $status): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $user->membership->forceFill(['status' => $status, 'member_number' => 'TEST-'.bin2hex(random_bytes(6)), 'joined_at' => now('UTC')])->save();
        $this->signedIn($user);
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload())->assertOk();
        self::assertSame($status, $user->membership->fresh()->status->value);
        self::assertSame(0, $user->membership->credentials()->count());
    }

    public static function inactiveMemberships(): array
    {
        return [['suspended'], ['ended']];
    }

    public function test_activation_failure_rolls_back_profile_name_audit_and_number_together(): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $before = $user->memberProfile->getAttributes();
        $name = $user->name;
        $next = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $delivery = Mockery::mock(RecordNotificationDelivery::class);
        $delivery->shouldReceive('handle')->andThrow(new RuntimeException('Synthetic profile activation failure.'));
        $this->app->instance(RecordNotificationDelivery::class, $delivery);
        $this->signedIn($user);
        $this->requestJson('PATCH', '/api/v1/me/profile', $this->payload())->assertStatus(500)->assertJsonPath('error.code', 'SERVER_ERROR');
        self::assertSame($before, $user->memberProfile->fresh()->getAttributes());
        self::assertSame($name, $user->fresh()->name);
        self::assertSame(MembershipStatus::PendingVerification, $user->membership->fresh()->status);
        self::assertSame($next, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(0, $user->membership->credentials()->count());
        self::assertSame(0, AuditLog::query()->where('action', 'member.profile_updated')->where('actor_user_id', $user->id)->count());
    }
}
