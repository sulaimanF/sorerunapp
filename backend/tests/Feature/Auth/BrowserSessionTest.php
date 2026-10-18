<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\BrowserSession;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\TestCase;

final class BrowserSessionTest extends TestCase
{
    private ?Connection $sessionConnection = null;

    private array $browserCookies = [];

    private const PASSWORD = 'Test-only-password-123';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection();
        if (! app()->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'sorerunapp_test'
            || config('session.driver') !== 'database' || config('session.connection') !== 'mysql'
            || config('session.table') !== 'sessions' || config('cache.default') !== 'array'
            || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')
            || ! Schema::hasTable('sessions') || ! Schema::hasTable('memberships')) {
            throw new RuntimeException('Session tests require testing, MySQL 8.4 sorerunapp_test, database sessions, array cache, and stages 01-03.');
        }
        DB::statement("SET SESSION time_zone = '+00:00'");
        $connection->beginTransaction();
        $this->sessionConnection = $connection;
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        config([
            'sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->sessionConnection !== null && $this->sessionConnection->transactionLevel() > 0) {
                $this->sessionConnection->rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_guest_cannot_read_current_member(): void
    {
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_unverified_member_logs_in_with_rotated_database_session_without_activation(): void
    {
        $user = $this->member();
        $this->csrf();
        $before = $this->sessionId();
        $domainCounts = $this->domainCounts();
        $response = $this->login($user)->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.membership.status', 'pending_verification')
            ->assertJsonPath('data.membership.member_number', null)
            ->assertJsonPath('data.profile_complete', true)->assertJsonPath('data.roles', ['member']);
        $after = $this->sessionId();
        self::assertNotSame($before, $after);
        self::assertFalse(DB::table('sessions')->where('id', $before)->exists());
        self::assertTrue(DB::table('sessions')->where('id', $after)->where('user_id', $user->id)->exists());
        self::assertSame($user->auth_version, app('session')->driver()->get(BrowserSession::AUTH_VERSION));
        self::assertSame($domainCounts, $this->domainCounts());
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(MembershipStatus::PendingVerification, $user->membership->fresh()->status);
        $this->assertSafeResponse($response);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_cookie_authentication_persists_after_guard_and_session_instances_are_reloaded(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_verified_member_can_log_in_without_reissuing_membership_or_credential(): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $this->csrf();
        $before = $this->domainCounts();
        $this->login($user)->assertOk()->assertJsonPath('data.user.email_verified', true);
        self::assertSame($before, $this->domainCounts());
    }

    public function test_login_normalizes_email_and_preserves_password_spaces(): void
    {
        $password = '  Test-only-password-123  ';
        $user = $this->member($password);
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/login', ['email' => '  '.strtoupper($user->email).'  ', 'password' => $password])
            ->assertOk()->assertJsonPath('data.user.email', $user->email);
    }

    public function test_unknown_wrong_password_and_blocked_account_have_same_public_error(): void
    {
        $user = $this->member();
        $this->csrf();
        $wrong = $this->login($user, 'Wrong-password-123')->assertStatus(401);
        $unknown = $this->requestJson('POST', '/api/v1/auth/login', ['email' => 'unknown-'.bin2hex(random_bytes(8)).'@example.test', 'password' => self::PASSWORD])->assertStatus(401);
        $user->forceFill(['status' => AccountStatus::Blocked, 'blocked_at' => now('UTC')])->save();
        $blocked = $this->login($user)->assertStatus(401);
        self::assertSame($wrong->json('error'), $unknown->json('error'));
        self::assertSame($wrong->json('error'), $blocked->json('error'));
        $blocked->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        self::assertFalse(DB::table('sessions')->where('user_id', $user->id)->exists());
        self::assertSame(AccountStatus::Blocked, $user->fresh()->status);
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_input_cannot_authenticate(array $payload, string $field): void
    {
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/login', $payload)->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => [$field]]]);
    }

    public static function invalidCredentials(): array
    {
        $valid = ['email' => 'synthetic@example.test', 'password' => self::PASSWORD];

        return [
            'missing email' => [['password' => self::PASSWORD], 'email'],
            'invalid email' => [array_replace($valid, ['email' => 'invalid']), 'email'],
            'missing password' => [['email' => $valid['email']], 'password'],
            'array password' => [array_replace($valid, ['password' => ['invalid']]), 'password'],
            'long password' => [array_replace($valid, ['password' => str_repeat('x', 73)]), 'password'],
            'null byte password' => [array_replace($valid, ['password' => "Test-password\0"]), 'password'],
            'client role' => [array_merge($valid, ['role' => 'admin']), '_request'],
            'client version' => [array_merge($valid, ['auth_version' => 999]), '_request'],
            'remember flag' => [array_merge($valid, ['remember' => true]), '_request'],
        ];
    }

    public function test_login_requires_csrf_token(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], false)
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_MISMATCH');
    }

    public function test_login_rejects_invalid_csrf_token(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], true, ['X-XSRF-TOKEN' => 'invalid'])
            ->assertStatus(419);
    }

    public function test_stateless_login_cannot_bypass_csrf_stack(): void
    {
        $user = $this->member();
        $this->requestJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], false, ['Origin' => '', 'Referer' => ''])
            ->assertStatus(419);
    }

    public function test_untrusted_origin_cannot_log_in_even_with_session_cookie(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], true,
            ['Origin' => 'https://untrusted.example', 'Referer' => 'https://untrusted.example/'])->assertStatus(419);
    }

    public function test_logout_destroys_old_session_and_old_cookie_cannot_read_identity(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $oldCookies = $this->browserCookies;
        $oldId = $this->sessionId();
        $oldCsrf = app('session')->driver()->token();
        $response = $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
        self::assertSame('', $response->getContent());
        self::assertNotSame($oldId, $this->sessionId());
        self::assertNotSame($oldCsrf, app('session')->driver()->token());
        self::assertFalse(DB::table('sessions')->where('id', $oldId)->exists());
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
        $this->browserCookies = $oldCookies;
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_requires_csrf_and_failed_logout_keeps_session_usable(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('POST', '/api/v1/auth/logout', [], false)->assertStatus(419);
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_logout_is_authenticated(): void
    {
        $this->csrf();
        $this->requestJson('POST', '/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_blocked_account_is_rejected_and_current_session_is_invalidated(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $oldId = $this->sessionId();
        $user->forceFill(['status' => AccountStatus::Blocked, 'blocked_at' => now('UTC')])->save();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
        self::assertFalse(DB::table('sessions')->where('id', $oldId)->exists());
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_changed_auth_version_revokes_old_session_and_allows_new_login(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
        $this->login($user)->assertOk();
        self::assertSame($user->auth_version, app('session')->driver()->get(BrowserSession::AUTH_VERSION));
    }

    public function test_replayed_session_row_is_rejected_after_version_changes(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $oldCookies = $this->browserCookies;
        $row = (array) DB::table('sessions')->where('id', $this->sessionId())->first();
        $user->forceFill(['auth_version' => $user->auth_version + 1])->save();
        DB::table('sessions')->where('id', $row['id'])->delete();
        DB::table('sessions')->insert($row);
        $this->browserCookies = $oldCookies;
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    #[DataProvider('invalidSessionVersions')]
    public function test_missing_or_untrusted_session_version_is_rejected(mixed $value): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        if ($value === null) {
            app('session')->driver()->forget(BrowserSession::AUTH_VERSION);
        } else {
            app('session')->driver()->put(BrowserSession::AUTH_VERSION, $value);
        }
        app('session')->driver()->save();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    public static function invalidSessionVersions(): array
    {
        return ['missing' => [null], 'string instead of server integer' => ['1'], 'invalid future version' => [999]];
    }

    public function test_deleted_database_session_cannot_authenticate_from_guard_memory(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        DB::table('sessions')->where('id', $this->sessionId())->delete();
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_expired_database_session_cannot_authenticate(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        DB::table('sessions')->where('id', $this->sessionId())->update(['last_activity' => time() - ((int) config('session.lifetime') + 1) * 60]);
        $this->requestJson('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_affects_current_browser_and_keeps_other_browser_session(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $firstBrowser = $this->browserCookies;
        $this->browserCookies = [];
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
        $this->browserCookies = $firstBrowser;
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_switching_account_removes_previous_identity_and_session(): void
    {
        $first = $this->member();
        $second = $this->member();
        $this->csrf();
        $this->login($first)->assertOk();
        $oldId = $this->sessionId();
        $this->login($second)->assertOk()->assertJsonPath('data.user.id', $second->id);
        self::assertFalse(DB::table('sessions')->where('id', $oldId)->exists());
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $second->id);
    }

    public function test_roles_are_fresh_and_legacy_alias_cannot_bypass_block_guard(): void
    {
        $user = $this->member();
        $this->csrf();
        $this->login($user)->assertOk();
        $user->roles()->detach();
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.roles', []);
        $this->requestJson('GET', '/api/user')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $user->forceFill(['status' => AccountStatus::Blocked, 'blocked_at' => now('UTC')])->save();
        $this->requestJson('GET', '/api/user')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_BLOCKED');
    }

    #[DataProvider('membershipStatuses')]
    public function test_membership_status_does_not_replace_account_login_status(string $status): void
    {
        $user = $this->member();
        $membership = $user->membership;
        if ($status !== 'pending_verification') {
            $user->forceFill(['email_verified_at' => now('UTC')])->save();
            $membership->forceFill(['member_number' => 'TEST-'.strtoupper(bin2hex(random_bytes(6))), 'joined_at' => now('UTC')]);
        }
        $membership->forceFill(['status' => $status])->save();
        $this->csrf();
        $this->login($user)->assertOk()->assertJsonPath('data.membership.status', $status);
        self::assertSame($status, $membership->fresh()->status->value);
    }

    public static function membershipStatuses(): array
    {
        return array_map(static fn (MembershipStatus $status): array => [$status->value], MembershipStatus::cases());
    }

    public function test_incomplete_profile_and_missing_membership_are_reported_without_activation(): void
    {
        $user = $this->member();
        $user->memberProfile()->delete();
        $user->membership()->delete();
        $this->csrf();
        $this->login($user)->assertOk()->assertJsonPath('data.profile_complete', false)->assertJsonPath('data.membership', null);
    }

    public function test_email_rate_limit_counts_failed_and_successful_attempts(): void
    {
        $user = $this->member();
        $this->csrf();
        for ($i = 0; $i < 5; $i++) {
            $this->login($user, 'Wrong-password-123')->assertStatus(401);
        }
        $this->login($user)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_ip_rate_limit_applies_across_different_emails(): void
    {
        $this->csrf();
        for ($i = 0; $i < 20; $i++) {
            $this->requestJson('POST', '/api/v1/auth/login', ['email' => 'unknown-'.bin2hex(random_bytes(8)).'@example.test', 'password' => self::PASSWORD])->assertStatus(401);
        }
        $this->requestJson('POST', '/api/v1/auth/login', ['email' => 'last@example.test', 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_malformed_json_and_wrong_content_type_are_rejected(): void
    {
        $this->call('POST', 'http://localhost:8000/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '"scalar"')
            ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        $this->call('POST', 'http://localhost:8000/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], 'invalid')
            ->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
    }

    public function test_session_cookie_is_httponly_and_no_remember_cookie_is_created(): void
    {
        $user = $this->member();
        $this->csrf();
        $response = $this->login($user)->assertOk();
        $found = false;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $found = true;
                self::assertTrue($cookie->isHttpOnly());
                self::assertSame('lax', $cookie->getSameSite());
            }
            self::assertFalse(str_starts_with($cookie->getName(), 'remember_'));
        }
        self::assertTrue($found);
    }

    public function test_successful_login_rehashes_password_when_configured_cost_changes(): void
    {
        $user = $this->member();
        $beforeHash = $user->password;
        config(['hashing.bcrypt.rounds' => 5]);
        Hash::driver('bcrypt')->setRounds(5);
        $this->csrf();
        $this->login($user)->assertOk();
        $fresh = $user->fresh();
        self::assertNotSame($beforeHash, $fresh->password);
        self::assertTrue(Hash::check(self::PASSWORD, $fresh->password));
        self::assertFalse(Hash::needsRehash($fresh->password));
        self::assertSame($user->auth_version, $fresh->auth_version);
    }

    private function member(string $password = self::PASSWORD): User
    {
        $user = User::factory()->create(['email' => 'session-'.bin2hex(random_bytes(8)).'@example.test', 'password' => $password]);
        $user->forceFill(['email_verified_at' => null])->save();
        $user->memberProfile()->create(['full_name' => 'Anggota Sesi Uji', 'birth_date' => '2000-01-01', 'phone' => '+6281234567890', 'city' => 'Kota Uji']);
        $user->membership()->save(new Membership);
        $user->roles()->attach(Role::query()->where('code', 'member')->value('id'), ['assigned_by' => null, 'assigned_at' => now('UTC')]);

        return $user;
    }

    private function csrf(): void
    {
        $this->requestJson('GET', '/sanctum/csrf-cookie')->assertNoContent();
        self::assertArrayHasKey('XSRF-TOKEN', $this->browserCookies);
    }

    private function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->requestJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => $password]);
    }

    private function requestJson(string $method, string $path, array $data = [], bool $csrf = true, array $extraHeaders = []): TestResponse
    {
        // Rebuild per-request services so a cached guard cannot fake cookie authentication.
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        Auth::shouldUse('web');
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->flushHeaders();
        $this->unencryptedCookies = [];
        $this->withUnencryptedCookies($this->browserCookies)->withCredentials();
        $headers = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/', 'Accept' => 'application/json'];
        if ($csrf && isset($this->browserCookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $this->browserCookies['XSRF-TOKEN'];
        }
        $response = $this->json($method, 'http://localhost:8000'.$path, $data, array_replace($headers, $extraHeaders));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() > 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->browserCookies[$cookie->getName()]);
            } else {
                $this->browserCookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    private function sessionId(): string
    {
        return app('session')->driver()->getId();
    }

    private function domainCounts(): array
    {
        return array_map(static fn (string $table): int => DB::table($table)->count(), [
            'users', 'memberships', 'member_profiles', 'member_credentials', 'policy_acknowledgements', 'audit_logs', 'notification_deliveries',
        ]);
    }

    private function assertSafeResponse(TestResponse $response): void
    {
        $data = $response->json('data');
        self::assertSame(['user', 'roles', 'profile_complete', 'membership'], array_keys($data));
        self::assertSame(['id', 'full_name', 'email', 'email_verified'], array_keys($data['user']));
        foreach (['password', 'remember_token', 'auth_version', 'token_encrypted', 'token_hash', 'token', 'photo_path', 'phone', 'birth_date'] as $key) {
            self::assertStringNotContainsString('"'.$key.'":', $response->getContent());
        }
        self::assertStringNotContainsString(self::PASSWORD, $response->getContent());
        self::assertTrue(Str::isUuid($response->json('meta.request_id')));
        self::assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-ID'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
