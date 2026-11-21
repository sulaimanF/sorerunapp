<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\AcceptAdminInvitation;
use App\Actions\Administration\ChangeAdministrativePassword;
use App\Actions\Auth\RequestEmailChange;
use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Memberships\ActivatePendingMembership;
use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AdministrationFixtures;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\TestCase;

final class AdministrativePasswordTest extends TestCase
{
    use AdministrationFixtures, VerificationBrowser;

    private const NEW_PASSWORD = 'New-administrative-password-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationDatabase();
        DB::beginTransaction();
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        config(['sanctum.stateful' => ['localhost:3000', 'localhost:8000'], 'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function values(): array
    {
        return ['current_password' => 'Test-only-password-123', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }

    public function test_member_admin_and_super_can_change_own_password_and_must_login_again(): void
    {
        foreach (['member', 'admin', 'super_admin'] as $role) {
            Cache::store('array')->flush();
            $this->browserCookies = [];
            $actor = $this->administrationActor($role);
            $emailVerified = $actor->getRawOriginal('email_verified_at');
            $this->csrf();
            $this->login($actor)->assertOk();
            $oldSession = DB::table('sessions')->where('user_id', $actor->id)->first();
            $oldCookies = $this->browserCookies;
            $this->requestJson('POST', '/api/v1/me/password', $this->values())->assertNoContent();
            self::assertSame(2, $actor->fresh()->auth_version);
            self::assertTrue(Hash::check(self::NEW_PASSWORD, $actor->fresh()->password));
            self::assertSame($emailVerified, $actor->fresh()->getRawOriginal('email_verified_at'));
            self::assertSame([$role], $actor->roles->pluck('code')->map(fn ($code) => $code->value)->all());
            self::assertSame(0, DB::table('sessions')->where('user_id', $actor->id)->count());
            $this->requestJson('GET', '/api/v1/auth/me')->assertUnauthorized();
            DB::table('sessions')->insert((array) $oldSession);
            $this->browserCookies = $oldCookies;
            $this->requestJson('GET', '/api/v1/auth/me')->assertUnauthorized();
            $this->csrf();
            $this->login($actor)->assertUnauthorized();
            $this->login($actor, self::NEW_PASSWORD)->assertOk();
            $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
            $audit = AuditLog::query()->where('action', 'auth.password_changed')->where('target_id', $actor->id)->firstOrFail();
            self::assertSame(['sessions_revoked' => true], $audit->after);
            Notification::assertNothingSent();
        }
    }

    public function test_super_changes_admin_without_email_and_preserves_own_session_and_account_data(): void
    {
        $actor = $this->administrationActor();
        $target = $this->administrationActor('admin');
        $before = $target->getRawOriginal();
        $this->csrf();
        $this->login($actor)->assertOk();
        $actorSession = DB::table('sessions')->where('user_id', $actor->id)->value('id');
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->requestJson('POST', '/api/v1/admin/accounts/'.$target->id.'/password', $this->values())->assertNoContent();
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $actor->id);
        self::assertTrue(Hash::check(self::NEW_PASSWORD, $target->fresh()->password));
        self::assertSame(2, $target->fresh()->auth_version);
        self::assertSame(1, $actor->fresh()->auth_version);
        self::assertTrue(DB::table('sessions')->where('id', $actorSession)->exists());
        self::assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        self::assertSame($before['email_verified_at'], $target->fresh()->getRawOriginal('email_verified_at'));
        self::assertSame($before['email'], $target->fresh()->email);
        self::assertSame(AccountStatus::Enabled, $target->fresh()->status);
        self::assertNull($target->membership);
        self::assertNull($target->memberProfile);
        self::assertSame(1, $target->roles()->count());
        $this->requestJson('GET', '/api/v1/admin/accounts')->assertOk()->assertJsonFragment(['user_id' => $target->id, 'can_change_password' => true]);
        Notification::assertNothingSent();
    }

    public function test_permission_csrf_password_validation_and_extra_fields_are_enforced(): void
    {
        $target = $this->administrationActor('admin');
        $path = '/api/v1/admin/accounts/'.$target->id.'/password';
        $this->csrf();
        $this->requestJson('POST', $path, $this->values())->assertUnauthorized();
        foreach (['admin', 'staff', 'member'] as $role) {
            $this->login($this->administrationActor($role))->assertOk();
            $this->requestJson('POST', $path, $this->values())->assertForbidden();
            if ($role === 'staff') {
                $this->requestJson('POST', '/api/v1/me/password', $this->values())->assertForbidden();
            }
            $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
            $this->csrf();
        }
        $actor = $this->administrationActor();
        Cache::store('array')->flush();
        $this->login($actor)->assertOk();
        $this->requestJson('POST', $path, $this->values(), false)->assertStatus(419);
        foreach ([
            ['current_password' => 'wrong'], ['password' => 'short', 'password_confirmation' => 'short'],
            ['password_confirmation' => 'mismatch'], ['password' => str_repeat('a', 73)],
            ['password' => "Invalid-null\0-password"], ['role' => 'super_admin'], ['user_id' => $actor->id], ['email_verified_at' => now()->toIso8601String()],
        ] as $invalid) {
            Cache::store('array')->flush();
            $this->requestJson('POST', $path, [...$this->values(), ...$invalid])->assertStatus(422);
            self::assertSame(1, $target->fresh()->auth_version);
        }
        Cache::store('array')->flush();
        for ($i = 0; $i < 5; $i++) {
            $this->requestJson('POST', $path, [...$this->values(), 'current_password' => 'wrong'])->assertStatus(422);
        }
        $this->requestJson('POST', $path, $this->values())->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_unactivated_blocked_super_member_and_self_targets_cannot_bypass_activation(): void
    {
        $actor = $this->administrationActor();
        $pending = User::findOrFail($this->provisionAdmin($actor)['user_id']);
        $blocked = $this->administrationActor('admin');
        $blocked->forceFill(['status' => 'blocked'])->save();
        foreach ([$pending, $blocked, $actor, $this->administrationActor(), $this->administrationActor('member')] as $target) {
            $before = $target->fresh()->getRawOriginal();
            try {
                app(ChangeAdministrativePassword::class)->handle($actor->id, 1, $this->values(), (string) Str::uuid(), $target->id);
                self::fail('Expected protected target.');
            } catch (ApiProblem $problem) {
                self::assertSame('ADMIN_PASSWORD_NOT_ALLOWED', $problem->errorCode);
            }
            self::assertSame($before, $target->fresh()->getRawOriginal());
        }
        self::assertNull($pending->fresh()->email_verified_at);
    }

    public function test_password_change_cancels_pending_email_and_reset_proofs_without_new_delivery(): void
    {
        $actor = $this->administrationActor();
        $target = User::findOrFail($this->provisionAdmin($actor)['user_id']);
        $proof = $this->invitationProof($target->id);
        app(AcceptAdminInvitation::class)->handle([...$proof, 'password' => 'Target-old-password-123'], (string) Str::uuid());
        app(RequestPasswordReset::class)->handle($target->email);
        app(RequestEmailChange::class)->handle($target->id, 2, 'pending-'.Str::uuid().'@example.test', 'Target-old-password-123', (string) Str::uuid());
        $beforeCount = NotificationDelivery::query()->where('user_id', $target->id)->count();
        self::assertTrue(DB::table('password_reset_tokens')->where('email', $target->email)->exists());
        app(ChangeAdministrativePassword::class)->handle($actor->id, 1, $this->values(), (string) Str::uuid(), $target->id);
        self::assertFalse(DB::table('password_reset_tokens')->where('email', $target->email)->exists());
        self::assertFalse(DB::table('email_change_requests')->where('user_id', $target->id)->whereNull('revoked_at')->whereNull('consumed_at')->exists());
        self::assertSame($beforeCount, NotificationDelivery::query()->where('user_id', $target->id)->count());
        self::assertFalse(NotificationDelivery::query()->where('user_id', $target->id)->whereIn('status', ['pending', 'processing', 'failed'])->exists());
        self::assertNotNull(AdminInvitation::findOrFail($proof['invitation_id'])->consumed_at);
    }

    public function test_password_and_revocations_roll_back_when_audit_fails(): void
    {
        $actor = $this->administrationActor();
        $target = $this->administrationActor('admin');
        $before = $target->fresh()->getRawOriginal();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new RuntimeException('Synthetic password audit failure.');
            }
        });
        try {
            app(ChangeAdministrativePassword::class)->handle($actor->id, 1, $this->values(), (string) Str::uuid(), $target->id);
            self::fail('Expected rollback.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic password audit failure.', $error->getMessage());
        }
        self::assertSame($before, $target->fresh()->getRawOriginal());
    }

    public function test_multi_role_admin_can_change_password_without_changing_membership_or_sequence(): void
    {
        $actor = $this->administrationActor('admin');
        $actor->roles()->attach(Role::query()->where('code', 'member')->value('id'), ['assigned_at' => now('UTC')]);
        $membership = $actor->membership()->save(new Membership);
        $before = $membership->fresh()->getRawOriginal();
        $sequence = DB::table('member_number_sequences')->get()->toJson();
        $credentials = DB::table('member_credentials')->count();
        app(ChangeAdministrativePassword::class)->handle($actor->id, 1, $this->values(), (string) Str::uuid());
        self::assertSame($before, $membership->fresh()->getRawOriginal());
        self::assertSame($sequence, DB::table('member_number_sequences')->get()->toJson());
        self::assertSame($credentials, DB::table('member_credentials')->count());
        self::assertSame(2, $actor->roles()->count());
    }

    public function test_actor_status_role_and_version_are_required_for_password_change(): void
    {
        $target = $this->administrationActor('admin');
        foreach (['blocked', 'unverified', 'stale', 'role_revoked'] as $case) {
            $actor = $this->administrationActor();
            $this->browserCookies = [];
            $this->csrf();
            $this->login($actor)->assertOk();
            match ($case) {
                'blocked' => $actor->forceFill(['status' => 'blocked'])->save(),
                'unverified' => $actor->forceFill(['email_verified_at' => null])->save(),
                'stale' => $actor->forceFill(['auth_version' => 2])->save(),
                'role_revoked' => $actor->roles()->detach(),
            };
            $this->requestJson('POST', '/api/v1/admin/accounts/'.$target->id.'/password', $this->values())->assertStatus($case === 'stale' ? 401 : 403);
            self::assertSame(1, $target->fresh()->auth_version);
        }
    }

    public function test_member_profile_password_change_preserves_membership_qr_and_member_number(): void
    {
        config(['sorerunapp.membership.number_prefix' => 'SRC']);
        $member = $this->administrationActor('member');
        $member->memberProfile()->create(['full_name' => 'Member Password Uji', 'birth_date' => '2000-01-01', 'phone' => '+6281234567890', 'city' => 'Kota Uji']);
        $membership = $member->membership()->save(new Membership);
        $lockedMembership = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
        $lockedMember = User::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
        app(ActivatePendingMembership::class)->handleLocked($lockedMember, $lockedMembership, (string) Str::uuid(), CarbonImmutable::now('UTC'));
        $beforeMembership = $membership->fresh()->getRawOriginal();
        $beforeProfile = $member->memberProfile->getRawOriginal();
        $credentialDigest = fn () => hash('sha256', serialize(DB::table('member_credentials')->where('membership_id', $membership->id)->get()->all()));
        $beforeCredential = $credentialDigest();
        $sequence = DB::table('member_number_sequences')->get()->toJson();
        $this->csrf();
        $this->login($member)->assertOk();
        $this->requestJson('POST', '/api/v1/me/password', $this->values(), false)->assertStatus(419);
        foreach ([['current_password' => 'wrong'], ['password' => 'short', 'password_confirmation' => 'short'], ['password_confirmation' => 'different'], ['user_id' => 1]] as $invalid) {
            $this->requestJson('POST', '/api/v1/me/password', [...$this->values(), ...$invalid])->assertStatus(422);
            self::assertSame(1, $member->fresh()->auth_version);
        }
        $this->requestJson('POST', '/api/v1/me/password', $this->values())->assertNoContent();
        self::assertSame($beforeMembership, $membership->fresh()->getRawOriginal());
        self::assertSame($beforeProfile, $member->memberProfile->fresh()->getRawOriginal());
        self::assertSame($beforeCredential, $credentialDigest());
        self::assertSame($sequence, DB::table('member_number_sequences')->get()->toJson());
        $this->requestJson('GET', '/api/v1/me/profile')->assertUnauthorized();
        $this->csrf();
        $this->login($member)->assertUnauthorized();
        $this->login($member, self::NEW_PASSWORD)->assertOk();
        $this->requestJson('GET', '/api/v1/me/profile')->assertOk();
    }
}
