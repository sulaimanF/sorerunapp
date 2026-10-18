<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Notifications\RecordEmailVerificationDelivery;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Enums\RoleCode;
use App\Models\MemberNumberSequence;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\TestCase;

final class RegisterMemberTest extends TestCase
{
    private ?Connection $registrationConnection = null;

    private const URL = 'http://localhost:8000/api/v1/auth/register';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();

        if (! app()->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'sorerunapp_test'
            || config('cache.default') !== 'array') {
            throw new RuntimeException('Registration tests require testing + MySQL + sorerunapp_test + array cache.');
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        if (! str_starts_with($version, '8.4.') || ! Schema::hasTable('notification_deliveries')) {
            throw new RuntimeException('Apply stage 01 schema on real MySQL 8.4 before registration tests.');
        }

        DB::statement("SET SESSION time_zone = '+00:00'");
        $connection->beginTransaction();
        $this->registrationConnection = $connection;
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        Mail::fake();
        Notification::fake();
        Queue::fake();

        config([
            'sorerunapp.registration.privacy_notice_version' => 'test-v1',
            'sorerunapp.registration.terms_version' => 'test-v1',
            'sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class,
        ]);

        // Preserve the real encrypted cookie values returned by the CSRF endpoint.
        $this->withCredentials();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->registrationConnection !== null && $this->registrationConnection->transactionLevel() > 0) {
                $this->registrationConnection->rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_registration_commits_pending_account_profile_role_policies_and_outbox(): void
    {
        $payload = $this->payload();
        $nextNumber = MemberNumberSequence::query()->findOrFail('membership')->next_value;
        $response = $this->register($payload);
        $response->assertStatus(201)->assertJsonPath('data.result', 'registration_received');
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        self::assertSame(AccountStatus::Enabled, $user->status);
        self::assertNull($user->email_verified_at);
        self::assertTrue(Hash::check($payload['password'], $user->password));
        self::assertSame(MembershipStatus::PendingVerification, $user->membership->status);
        self::assertNull($user->membership->member_number);
        self::assertTrue($user->memberProfile->isComplete());
        self::assertSame('+6281234567890', $user->memberProfile->phone);
        self::assertSame([RoleCode::Member], $user->roles->pluck('code')->all());
        self::assertNull($user->roles->sole()->pivot->assigned_by);
        self::assertSame(['privacy_notice', 'terms'], $user->policyAcknowledgements()->orderBy('policy_type')->pluck('policy_type')->all());
        self::assertSame(['test-v1', 'test-v1'], $user->policyAcknowledgements()->orderBy('policy_type')->pluck('policy_version')->all());
        self::assertSame(1, $user->notificationDeliveries()->where('status', 'pending')->count());
        self::assertSame('email_verification', $user->notificationDeliveries->sole()->type);
        self::assertSame(0, $user->membership->credentials()->count());
        self::assertSame($nextNumber, MemberNumberSequence::query()->findOrFail('membership')->next_value);
        self::assertSame(1, DB::table('audit_logs')->where('target_id', $user->membership->id)->where('action', 'member.registered')->count());

        foreach (['email', 'password', 'user', 'user_id', 'member_number', 'qr', 'token'] as $field) {
            self::assertArrayNotHasKey($field, $response->json('data'));
        }
        self::assertStringNotContainsString($payload['email'], $response->getContent());
        self::assertStringNotContainsString($payload['password'], $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGuest();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_duplicate_normalized_email_has_same_public_receipt_and_no_overwrite(): void
    {
        $payload = $this->payload();
        $first = $this->register($payload)->assertStatus(201);
        $counts = $this->counts();
        $secondPayload = array_replace($payload, ['email' => '  '.strtoupper($payload['email']).'  ', 'full_name' => 'Nama Berbeda']);
        $second = $this->register($secondPayload)->assertStatus(201);
        self::assertSame($first->json('data'), $second->json('data'));
        self::assertSame($counts, $this->counts());
        self::assertSame('Anggota Uji', User::query()->where('email', $payload['email'])->firstOrFail()->memberProfile->full_name);
    }

    public function test_existing_blocked_account_is_not_reactivated_by_registration(): void
    {
        $payload = $this->payload();
        $this->register($payload)->assertStatus(201);
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $user->forceFill(['status' => AccountStatus::Blocked, 'blocked_at' => now('UTC')])->save();
        $counts = $this->counts();
        $this->register($payload)->assertStatus(201)->assertJsonPath('data.result', 'registration_received');
        self::assertSame($counts, $this->counts());
        self::assertSame(AccountStatus::Blocked, $user->fresh()->status);
    }

    public function test_existing_verified_account_receives_same_generic_response(): void
    {
        $existing = User::factory()->create();
        $payload = $this->payload(['email' => $existing->email]);
        $counts = $this->counts();
        $this->register($payload)->assertStatus(201)->assertJsonPath('data.result', 'registration_received');
        self::assertSame($counts, $this->counts());
        self::assertTrue($existing->fresh()->hasVerifiedEmail());
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected_without_partial_records(array $overrides, string $field): void
    {
        $counts = $this->counts();
        $response = $this->register($this->payload($overrides));
        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertArrayHasKey($field, $response->json('error.fields'));
        self::assertSame($counts, $this->counts());
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['full_name' => null], 'full_name'],
            'short name' => [['full_name' => 'A'], 'full_name'],
            'invalid email' => [['email' => 'invalid-email'], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'wrong confirmation' => [['password_confirmation' => 'Not-the-same-123'], 'password'],
            'null byte password' => [['password' => "Test-password-123\0", 'password_confirmation' => "Test-password-123\0"], 'password'],
            'invalid calendar date' => [['birth_date' => '2026-02-30'], 'birth_date'],
            'future birth date' => [['birth_date' => '2999-01-01'], 'birth_date'],
            'phone letters' => [['phone' => '0812-ABC'], 'phone'],
            'phone numeric type' => [['phone' => 81234567890], 'phone'],
            'missing city' => [['city' => '   '], 'city'],
            'privacy unchecked' => [['privacy_notice_accepted' => false], 'privacy_notice_accepted'],
            'terms unchecked' => [['terms_accepted' => false], 'terms_accepted'],
            'outdated policy' => [['privacy_notice_version' => 'old-v0'], 'privacy_notice_version'],
            'client status injection' => [['status' => 'active'], '_request'],
            'client role injection' => [['roles' => ['admin']], '_request'],
            'client owner injection' => [['user_id' => 1], '_request'],
        ];
    }

    public function test_same_name_and_phone_are_allowed_for_different_accounts(): void
    {
        $first = $this->payload();
        $second = $this->payload();
        $this->register($first)->assertStatus(201);
        $this->register($second)->assertStatus(201);
        self::assertNotSame($first['email'], $second['email']);
        self::assertSame(2, DB::table('users')->whereIn('email', [$first['email'], $second['email']])->count());
    }

    public function test_minor_can_register_without_photo_or_gender(): void
    {
        $payload = $this->payload(['birth_date' => now('UTC')->subYears(10)->toDateString()]);
        $this->register($payload)->assertStatus(201);
        $profile = User::query()->where('email', $payload['email'])->firstOrFail()->memberProfile;
        self::assertNull($profile->gender);
        self::assertNull($profile->photo_path);
        self::assertTrue($profile->isComplete());
    }

    public function test_password_over_bcrypt_byte_limit_is_rejected_without_truncation(): void
    {
        $password = str_repeat('界', 25);
        $counts = $this->counts();
        $this->register($this->payload(['password' => $password, 'password_confirmation' => $password]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        self::assertSame($counts, $this->counts());
    }

    public function test_password_whitespace_is_preserved(): void
    {
        $password = '  Test-only-password-123  ';
        $payload = $this->payload(['password' => $password, 'password_confirmation' => $password]);
        $this->register($payload)->assertStatus(201);
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        self::assertTrue(Hash::check($password, $user->password));
        self::assertFalse(Hash::check(trim($password), $user->password));
    }

    public function test_missing_csrf_header_is_rejected_by_real_csrf_middleware(): void
    {
        $this->csrfHeaders();
        $counts = $this->counts();
        $this->postJson(self::URL, $this->payload(), $this->browserHeaders())
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_MISMATCH');
        self::assertSame($counts, $this->counts());
    }

    public function test_invalid_csrf_header_is_rejected(): void
    {
        $headers = array_replace($this->csrfHeaders(), ['X-XSRF-TOKEN' => 'invalid-token']);
        $this->postJson(self::URL, $this->payload(), $headers)->assertStatus(419)->assertJsonPath('error.code', 'CSRF_MISMATCH');
    }

    public function test_omitting_origin_and_referer_cannot_bypass_session_and_csrf(): void
    {
        $this->csrfHeaders();
        $this->flushHeaders();
        $counts = $this->counts();
        $this->postJson(self::URL, $this->payload())->assertStatus(419)->assertJsonPath('error.code', 'CSRF_MISMATCH');
        self::assertSame($counts, $this->counts());
    }

    public function test_untrusted_origin_and_referer_cannot_register_even_with_cookies(): void
    {
        $headers = array_replace($this->csrfHeaders(), ['Origin' => 'https://untrusted.example', 'Referer' => 'https://untrusted.example/']);
        $counts = $this->counts();
        $this->postJson(self::URL, $this->payload(), $headers)->assertStatus(419);
        self::assertSame($counts, $this->counts());
    }

    public function test_outbox_failure_rolls_back_all_registration_records(): void
    {
        config(['app.debug' => true]);
        $counts = $this->counts();
        $mock = Mockery::mock(RecordEmailVerificationDelivery::class);
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('synthetic delivery failure'));
        $this->app->instance(RecordEmailVerificationDelivery::class, $mock);
        $response = $this->register($this->payload());
        $response->assertStatus(500)->assertJsonPath('error.code', 'SERVER_ERROR');
        self::assertStringNotContainsString('synthetic delivery failure', $response->getContent());
        self::assertArrayNotHasKey('trace', $response->json());
        self::assertSame($counts, $this->counts());
    }

    public function test_missing_member_role_returns_service_error_without_account_write(): void
    {
        DB::table('user_roles')->whereIn('role_id', DB::table('roles')->where('code', 'member')->pluck('id'))->delete();
        DB::table('roles')->where('code', 'member')->delete();
        $counts = $this->counts();
        $this->register($this->payload())->assertStatus(503)->assertJsonPath('error.code', 'REGISTRATION_UNAVAILABLE');
        self::assertSame($counts, $this->counts());
    }

    public function test_missing_policy_configuration_does_not_create_account(): void
    {
        config(['sorerunapp.registration.terms_version' => null]);
        $counts = $this->counts();
        $this->register($this->payload())->assertStatus(503)->assertJsonPath('error.code', 'REGISTRATION_UNAVAILABLE');
        self::assertSame($counts, $this->counts());
    }

    public function test_ip_rate_limit_returns_retry_after(): void
    {
        $headers = $this->csrfHeaders();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::URL, $this->payload(), $headers)->assertStatus(201);
        }
        $this->postJson(self::URL, $this->payload(), $headers)
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_email_rate_limit_applies_to_repeated_generic_receipts(): void
    {
        $headers = $this->csrfHeaders();
        $payload = $this->payload();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::URL, $payload, $headers)->assertStatus(201);
        }
        $this->postJson(self::URL, $payload, $headers)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        self::assertSame(1, User::query()->where('email', $payload['email'])->count());
    }

    public function test_malformed_or_nonobject_json_is_rejected(): void
    {
        foreach (['{"invalid":', '"scalar"', '[1,2]'] as $body) {
            $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
                ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        }
    }

    public function test_non_json_content_type_is_rejected(): void
    {
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], 'name=test')
            ->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
    }

    public function test_large_registration_body_is_rejected(): void
    {
        $body = json_encode(['extra' => str_repeat('x', 17_000)], JSON_THROW_ON_ERROR);
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_request_id_is_server_generated_and_matches_response_header(): void
    {
        $headers = array_replace($this->csrfHeaders(), ['X-Request-ID' => 'client-controlled-value']);
        $response = $this->postJson(self::URL, $this->payload(), $headers)->assertStatus(201);
        $id = $response->json('meta.request_id');
        self::assertTrue(Str::isUuid($id));
        self::assertSame($id, $response->headers->get('X-Request-ID'));
        self::assertNotSame('client-controlled-value', $id);
    }

    public function test_api_not_found_uses_safe_error_envelope_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $response = $this->getJson('http://localhost:8000/api/v1/not-existing');
        $response->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        self::assertArrayNotHasKey('trace', $response->json());
        self::assertTrue(Str::isUuid($response->json('meta.request_id')));
    }

    private function register(array $payload): TestResponse
    {
        return $this->postJson(self::URL, $payload, $this->csrfHeaders());
    }

    private function csrfHeaders(): array
    {
        $response = $this->withHeaders($this->browserHeaders())->get('http://localhost:8000/sanctum/csrf-cookie');
        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        self::assertArrayHasKey('XSRF-TOKEN', $cookies);
        self::assertArrayHasKey(config('session.cookie'), $cookies);
        $this->withUnencryptedCookies($cookies);

        return array_merge($this->browserHeaders(), ['X-XSRF-TOKEN' => $cookies['XSRF-TOKEN']]);
    }

    private function browserHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/', 'Accept' => 'application/json'];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'full_name' => 'Anggota Uji', 'email' => 'registration-'.bin2hex(random_bytes(8)).'@example.test',
            'password' => 'Test-only-password-123', 'password_confirmation' => 'Test-only-password-123',
            'birth_date' => '2000-01-01', 'phone' => '0812-3456-7890', 'city' => 'Kota Uji',
            'privacy_notice_accepted' => true, 'privacy_notice_version' => 'test-v1',
            'terms_accepted' => true, 'terms_version' => 'test-v1',
        ], $overrides);
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['users', 'member_profiles', 'memberships', 'member_credentials', 'user_roles',
            'policy_acknowledgements', 'audit_logs', 'notification_deliveries'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
