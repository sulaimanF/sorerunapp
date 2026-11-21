<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\AcceptAdminInvitation;
use App\Actions\Administration\BootstrapSuperAdmin;
use App\Actions\Administration\CreateAdminAccount;
use App\Actions\Administration\ListAdminAccounts;
use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Auth\ResetMemberPassword;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\AdminProvisioning;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use App\Policies\MemberProfilePolicy;
use App\Services\Administration\AdminInvitationNotification;
use App\Services\Administration\AdministrativePermissions;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
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

final class AdminProvisioningTest extends TestCase
{
    use AdministrationFixtures, VerificationBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationDatabase();
        DB::beginTransaction();
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        config([
            'sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class,
            'sorerunapp.frontend_url' => 'http://localhost:3000',
        ]);
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

    public function test_admin_account_list_is_super_only_and_rejects_disabled_or_stale_sessions(): void
    {
        $this->csrf();
        $this->requestJson('GET', '/api/v1/admin/accounts')->assertUnauthorized();
        foreach (['member', 'staff', 'admin'] as $role) {
            $this->login($this->administrationActor($role))->assertOk();
            $this->requestJson('GET', '/api/v1/admin/accounts')->assertForbidden();
            $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
            $this->csrf();
        }
        foreach (['blocked', 'unverified', 'stale', 'role_revoked'] as $case) {
            Cache::store('array')->flush();
            $super = $this->administrationActor();
            $this->login($super)->assertOk();
            match ($case) {
                'blocked' => $super->forceFill(['status' => 'blocked'])->save(),
                'unverified' => $super->forceFill(['email_verified_at' => null])->save(),
                'stale' => $super->forceFill(['auth_version' => 2])->save(),
                'role_revoked' => $super->roles()->detach(),
            };
            $this->requestJson('GET', '/api/v1/admin/accounts')->assertStatus($case === 'stale' ? 401 : 403);
            $this->browserCookies = [];
            $this->csrf();
        }
    }

    public function test_admin_account_list_is_paginated_whitelisted_and_includes_legacy_and_multi_role_admins(): void
    {
        $super = $this->administrationActor();
        $legacy = $this->administrationActor('admin');
        $legacy->roles()->attach(Role::query()->where('code', 'member')->value('id'), ['assigned_at' => now('UTC')]);
        $pending = $this->provisionAdmin($super);
        $blocked = $this->administrationActor('admin');
        $blocked->forceFill(['status' => 'blocked'])->save();
        $member = $this->administrationActor('member');
        $this->csrf();
        $this->login($super)->assertOk();
        $response = $this->requestJson('GET', '/api/v1/admin/accounts')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.pagination.per_page', 20)->assertJsonStructure(['meta' => ['request_id']]);
        $rows = collect($response->json('data.items'))->keyBy('user_id');
        self::assertFalse($rows->has($super->id));
        self::assertFalse($rows->has($member->id));
        self::assertTrue($rows[$pending['user_id']]['has_invitation']);
        self::assertFalse($rows[$pending['user_id']]['email_verified']);
        self::assertFalse($rows[$legacy->id]['has_invitation']);
        self::assertFalse($rows[$legacy->id]['can_delete']);
        self::assertTrue($rows[$pending['user_id']]['can_delete']);
        self::assertTrue($rows[$legacy->id]['email_verified']);
        self::assertSame('blocked', $rows[$blocked->id]['account_status']);
        foreach ($rows as $row) {
            self::assertSame(['user_id', 'full_name', 'email', 'account_status', 'email_verified', 'has_invitation', 'can_delete', 'can_change_password'], array_keys($row));
        }
        $before = $response->json('data.pagination.total');
        for ($i = 0; $i < 21; $i++) {
            $this->administrationActor('admin');
        }
        $first = $this->requestJson('GET', '/api/v1/admin/accounts?page=1')->assertOk();
        $second = $this->requestJson('GET', '/api/v1/admin/accounts?page=2')->assertOk();
        self::assertCount(20, $first->json('data.items'));
        self::assertSame($before + 21, $first->json('data.pagination.total'));
        self::assertSame([], array_intersect(array_column($first->json('data.items'), 'user_id'), array_column($second->json('data.items'), 'user_id')));
        foreach (['page=0', 'page=-1', 'page=abc', 'page[]=1', 'page=1000001', 'role=member', 'user_id='.$member->id] as $query) {
            $this->requestJson('GET', '/api/v1/admin/accounts?'.$query)->assertStatus(422);
        }
        $this->requestJson('GET', '/api/v1/admin/accounts?page=1000000')->assertOk()->assertJsonPath('data.items', []);
        $this->requestJson('GET', '/api/v1/admin/accounts', ['page' => 1])->assertStatus(422);
        Cache::store('array')->flush();
        for ($i = 0; $i < 60; $i++) {
            $this->requestJson('GET', '/api/v1/admin/accounts')->assertOk();
        }
        $this->requestJson('GET', '/api/v1/admin/accounts')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_admin_account_list_rechecks_actor_permission_inside_transaction(): void
    {
        $actor = $this->administrationActor();
        $actor->roles()->detach();
        $this->expectException(AuthorizationException::class);
        app(ListAdminAccounts::class)->handle($actor->id, $actor->auth_version, 1);
    }

    public function test_only_super_admin_can_provision_and_extra_fields_and_csrf_are_rejected(): void
    {
        $data = ['full_name' => 'Admin Uji', 'email' => 'new-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid()];
        $this->csrf();
        $this->requestJson('POST', '/api/v1/admin/accounts', $data)->assertStatus(401);
        foreach (['member', 'staff', 'admin'] as $role) {
            $this->csrf();
            $this->login($this->administrationActor($role))->assertOk();
            $this->requestJson('POST', '/api/v1/admin/accounts', $data)->assertForbidden();
            $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
        }
        $this->csrf();
        $super = $this->administrationActor();
        $this->login($super)->assertOk()->assertJsonPath('data.roles', ['super_admin']);
        $this->requestJson('POST', '/api/v1/admin/accounts', $data, false)->assertStatus(419);
        foreach (['role', 'roles', 'status', 'actor_id', 'email_verified_at'] as $field) {
            Cache::store('array')->flush();
            $this->requestJson('POST', '/api/v1/admin/accounts', [...$data, $field => 'super_admin'])->assertStatus(422);
        }
        Cache::store('array')->flush();
        $this->requestJson('POST', '/api/v1/admin/accounts', $data)->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_idempotency_normalization_conflicts_and_no_membership_side_effects(): void
    {
        $actor = $this->administrationActor();
        $counts = $this->memberCounts();
        $data = ['full_name' => 'Admin Uji', 'email' => 'admin-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid()];
        $action = app(CreateAdminAccount::class);
        $one = $action->handle($actor->id, 1, $data, (string) Str::uuid());
        $two = $action->handle($actor->id, 1, $data, (string) Str::uuid());
        self::assertSame($one['user_id'], $two['user_id']);
        self::assertSame($counts, $this->memberCounts());
        self::assertSame(1, NotificationDelivery::query()->where('user_id', $one['user_id'])->count());
        self::assertSame(['admin'], User::findOrFail($one['user_id'])->roles->pluck('code')->map(fn ($code) => $code->value)->all());
        foreach ([
            [[...$data, 'full_name' => 'Data berbeda'], 'IDEMPOTENCY_CONFLICT'],
            [[...$data, 'request_key' => (string) Str::uuid()], 'EMAIL_UNAVAILABLE'],
        ] as [$payload, $code]) {
            try {
                $action->handle($actor->id, 1, $payload, (string) Str::uuid());
                self::fail('Expected conflict.');
            } catch (ApiProblem $problem) {
                self::assertSame($code, $problem->errorCode);
            }
        }
        self::assertNull(User::findOrFail($one['user_id'])->email_verified_at);
    }

    public function test_activation_is_one_time_and_does_not_issue_membership_or_auto_login(): void
    {
        $actor = $this->administrationActor();
        $target = $this->provisionAdmin($actor);
        $proof = $this->invitationProof($target['user_id']);
        $counts = $this->memberCounts();
        $password = 'New-test-password-123';
        $this->csrf();
        foreach (['invitation_id', 'email', 'token'] as $missing) {
            Cache::store('array')->flush();
            $incomplete = [...$proof, 'password' => $password, 'password_confirmation' => $password];
            unset($incomplete[$missing]);
            $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', $incomplete)->assertStatus(422);
            self::assertNull(User::findOrFail($target['user_id'])->email_verified_at);
        }
        Cache::store('array')->flush();
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', [...$proof, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', [...$proof, 'password' => $password, 'password_confirmation' => 'different'])->assertStatus(422);
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', [...$proof, 'password' => $password, 'password_confirmation' => $password], false)->assertStatus(419);
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', [...$proof, 'password' => $password, 'password_confirmation' => $password])->assertOk()->assertJsonPath('data.result', 'admin_activated');
        $user = User::findOrFail($target['user_id']);
        self::assertTrue(Hash::check($password, $user->password));
        self::assertSame(2, $user->auth_version);
        self::assertNotNull($user->email_verified_at);
        self::assertSame($counts, $this->memberCounts());
        self::assertNotNull(AdminProvisioning::query()->where('user_id', $user->id)->firstOrFail()->activated_at);
        $this->requestJson('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', [...$proof, 'password' => $password, 'password_confirmation' => $password])->assertStatus(422);
        $this->login($user, $password)->assertOk()->assertJsonPath('data.membership', null);
        $this->requestJson('GET', '/api/v1/me/account')->assertOk()->assertJsonPath('data.full_name', 'Admin Uji Sintetis');
    }

    public function test_invalid_expired_revoked_stale_and_blocked_invites_leave_no_partial_change(): void
    {
        foreach (['token', 'expired', 'revoked', 'stale', 'email', 'blocked', 'role'] as $case) {
            $target = $this->provisionAdmin($this->administrationActor());
            $proof = $this->invitationProof($target['user_id']);
            $user = User::findOrFail($target['user_id']);
            $invitation = AdminInvitation::findOrFail($proof['invitation_id']);
            if ($case === 'token') {
                $proof['token'] = str_repeat('0', 64);
            }
            if ($case === 'expired') {
                $invitation->forceFill(['expires_at' => now('UTC')->subSecond()])->save();
            }
            if ($case === 'revoked') {
                $invitation->forceFill(['revoked_at' => now('UTC')])->save();
            }
            if ($case === 'stale') {
                $user->forceFill(['auth_version' => 2])->save();
            }
            if ($case === 'email') {
                $user->forceFill(['email' => 'changed-'.Str::uuid().'@example.test'])->save();
            }
            if ($case === 'blocked') {
                $user->forceFill(['status' => 'blocked'])->save();
            }
            if ($case === 'role') {
                $user->roles()->detach();
            }
            $before = $user->fresh()->getRawOriginal('password');
            try {
                app(AcceptAdminInvitation::class)->handle([...$proof, 'password' => 'New-test-password-123'], (string) Str::uuid());
                self::fail('Expected invalid proof.');
            } catch (ApiProblem $problem) {
                self::assertSame('ADMIN_INVITATION_INVALID', $problem->errorCode);
            }
            self::assertSame($before, $user->fresh()->getRawOriginal('password'));
            self::assertNull($user->fresh()->email_verified_at);
            self::assertNull($invitation->fresh()->consumed_at);
        }
    }

    public function test_resend_revokes_old_proof_and_other_session_is_preserved(): void
    {
        $actor = $this->administrationActor();
        $target = $this->provisionAdmin($actor);
        $old = $this->invitationProof($target['user_id']);
        $this->csrf();
        $this->login($actor)->assertOk();
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', [...$old, 'password' => 'New-test-password-123', 'password_confirmation' => 'New-test-password-123'])->assertForbidden()->assertJsonPath('error.code', 'INVITATION_SESSION_CONFLICT');
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $actor->id);
        $this->requestJson('POST', '/api/v1/admin/accounts/'.$target['user_id'].'/invitation/resend')->assertStatus(429);
        $this->travel(61)->seconds();
        Cache::store('array')->flush();
        $this->requestJson('POST', '/api/v1/admin/accounts/'.$target['user_id'].'/invitation/resend')->assertStatus(202);
        self::assertNotNull(AdminInvitation::findOrFail($old['invitation_id'])->revoked_at);
        self::assertSame(2, AdminInvitation::query()->where('user_id', $target['user_id'])->count());
    }

    public function test_reset_password_revokes_invite_without_verifying_or_activating_account(): void
    {
        $target = $this->provisionAdmin($this->administrationActor());
        $proof = $this->invitationProof($target['user_id']);
        $user = User::findOrFail($target['user_id']);
        app(RequestPasswordReset::class)->handle($user->email);
        $delivery = NotificationDelivery::query()->where('user_id', $user->id)->where('type', 'password_reset')->firstOrFail();
        app(ResetMemberPassword::class)->handle([
            'email' => $user->email, 'token' => $delivery->payload_encrypted['token'],
            'password' => 'New-test-password-123', 'password_confirmation' => 'New-test-password-123',
        ], (string) Str::uuid());
        self::assertNull($user->fresh()->email_verified_at);
        self::assertNull(AdminProvisioning::query()->where('user_id', $user->id)->firstOrFail()->activated_at);
        self::assertNotNull(AdminInvitation::findOrFail($proof['invitation_id'])->revoked_at);
        $this->csrf();
        $this->login($user->fresh(), 'New-test-password-123')->assertForbidden()->assertJsonPath('error.code', 'ADMIN_ACTIVATION_REQUIRED');
    }

    public function test_super_access_and_revoked_or_stale_permission_rechecked_in_action(): void
    {
        $actor = $this->administrationActor();
        self::assertTrue(AdministrativePermissions::manage($actor));
        self::assertTrue(AdministrativePermissions::provision($actor));
        $this->csrf();
        $this->login($actor)->assertOk();
        $draft = $this->requestJson('GET', '/api/v1/admin/landing-page')->assertOk()->json('data');
        $content = $draft['content'];
        $content['hero']['title'] = 'Landing sintetis Super Admin';
        $content['hero']['description'] = 'Konten pengujian izin administratif.';
        $content['seo']['title'] = 'Stage11 sintetis';
        $content['seo']['description'] = 'Hanya database test terisolasi.';
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', ['version' => $draft['version'], 'content' => $content], false)->assertStatus(419);
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', ['version' => $draft['version'], 'content' => $content])->assertOk();
        $this->requestJson('GET', '/api/v1/admin/landing-page/preview')->assertOk();
        $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => $draft['version'] + 1])->assertOk();
        $this->getJson('/api/v1/public/landing-page')->assertOk()->assertJsonPath('data.publication.content.hero.title', $content['hero']['title']);
        $actor->roles()->detach();
        try {
            $this->provisionAdmin($actor);
            self::fail('Expected denial.');
        } catch (AuthorizationException) {
        }
        $actor->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'), ['assigned_at' => now('UTC')]);
        $actor->forceFill(['auth_version' => 2])->save();
        try {
            app(CreateAdminAccount::class)->handle($actor->id, 1, ['full_name' => 'Test', 'email' => 'stale@example.test', 'request_key' => (string) Str::uuid()], (string) Str::uuid());
            self::fail('Expected expired session.');
        } catch (ApiProblem $problem) {
            self::assertSame('SESSION_EXPIRED', $problem->errorCode);
        }
    }

    public function test_outbox_stores_encrypted_proof_and_rejects_revoked_delivery(): void
    {
        $target = $this->provisionAdmin($this->administrationActor());
        $delivery = NotificationDelivery::query()->where('user_id', $target['user_id'])->firstOrFail();
        $token = $delivery->payload_encrypted['token'];
        self::assertStringNotContainsString($token, $delivery->getRawOriginal('payload_encrypted'));
        self::assertNotNull(app(AdminInvitationNotification::class)->forDelivery($delivery, User::findOrFail($target['user_id'])));
        $other = $this->provisionAdmin($this->administrationActor());
        $stale = NotificationDelivery::query()->where('user_id', $other['user_id'])->firstOrFail();
        AdminInvitation::query()->where('user_id', $other['user_id'])->update(['revoked_at' => now('UTC')]);
        self::assertNull(app(AdminInvitationNotification::class)->forDelivery($stale, User::findOrFail($other['user_id'])));
    }

    public function test_bootstrap_preserves_roles_and_requires_a_verified_explicit_target(): void
    {
        $target = $this->administrationActor('member');
        $membership = $target->membership()->save(new Membership);
        $membershipBefore = $membership->fresh()->getRawOriginal();
        $sessions = ['id' => Str::random(40), 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Synthetic bootstrap test', 'payload' => '', 'last_activity' => time()];
        DB::table('sessions')->insert($sessions);
        $fence = DB::table('security_bootstraps')->where('name', 'first_super_admin')->first();
        if ($fence->completed_at !== null || DB::table('user_roles')->whereIn('role_id', Role::query()->where('code', 'super_admin')->select('id'))->exists()) {
            throw new RuntimeException('Bootstrap test requires an unused test fence and no Super Admin fixture.');
        }
        try {
            app(BootstrapSuperAdmin::class)->handle($target->id, 'different@example.test', (string) Str::uuid());
            self::fail('Expected explicit target validation.');
        } catch (ApiProblem $problem) {
            self::assertSame('BOOTSTRAP_TARGET_INVALID', $problem->errorCode);
        }
        app(BootstrapSuperAdmin::class)->handle($target->id, $target->email, (string) Str::uuid());
        self::assertSame(2, $target->fresh()->roles()->count());
        self::assertSame(2, $target->fresh()->auth_version);
        self::assertSame($membershipBefore, $membership->fresh()->getRawOriginal());
        self::assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'security.super_admin_bootstrapped')->where('target_id', $target->id)->count());
        try {
            app(BootstrapSuperAdmin::class)->handle($target->id, $target->email, (string) Str::uuid());
            self::fail('Expected one-time fence.');
        } catch (ApiProblem $problem) {
            self::assertSame('BOOTSTRAP_ALREADY_COMPLETED', $problem->errorCode);
        }
        $target->roles()->detach(Role::query()->where('code', 'super_admin')->value('id'));
        $second = $this->administrationActor('admin');
        try {
            app(BootstrapSuperAdmin::class)->handle($second->id, $second->email, (string) Str::uuid());
            self::fail('Removing a role must not reset the permanent bootstrap fence.');
        } catch (ApiProblem $problem) {
            self::assertSame('BOOTSTRAP_ALREADY_COMPLETED', $problem->errorCode);
        }
        self::assertSame(['admin'], $second->fresh()->roles->pluck('code')->map(fn ($code) => $code->value)->all());
    }

    public function test_bootstrap_rejects_blocked_and_unverified_targets_without_consuming_fence(): void
    {
        foreach (['blocked', 'unverified'] as $case) {
            $target = $this->administrationActor('admin');
            $target->forceFill($case === 'blocked' ? ['status' => 'blocked'] : ['email_verified_at' => null])->save();
            try {
                app(BootstrapSuperAdmin::class)->handle($target->id, $target->email, (string) Str::uuid());
                self::fail('Expected invalid bootstrap target.');
            } catch (ApiProblem $problem) {
                self::assertSame('BOOTSTRAP_TARGET_INVALID', $problem->errorCode);
            }
            self::assertSame(['admin'], $target->fresh()->roles->pluck('code')->map(fn ($code) => $code->value)->all());
            self::assertSame(1, $target->fresh()->auth_version);
            self::assertNull(DB::table('security_bootstraps')->where('name', 'first_super_admin')->value('completed_at'));
        }
    }

    public function test_creation_rolls_back_when_audit_fails(): void
    {
        $actor = $this->administrationActor();
        $email = 'rollback-'.Str::uuid().'@example.test';
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new RuntimeException('Synthetic audit failure.');
            }
        });
        try {
            app(CreateAdminAccount::class)->handle($actor->id, 1, ['full_name' => 'Rollback Test', 'email' => $email, 'request_key' => (string) Str::uuid()], (string) Str::uuid());
            self::fail('Expected rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic audit failure.', $exception->getMessage());
        }
        self::assertFalse(User::query()->where('email', $email)->exists());
    }

    public function test_activation_rolls_back_password_verification_and_consumption_when_audit_fails(): void
    {
        $target = $this->provisionAdmin($this->administrationActor());
        $proof = [...$this->invitationProof($target['user_id']), 'password' => 'New-test-password-123'];
        $before = User::findOrFail($target['user_id'])->getRawOriginal('password');
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new RuntimeException('Synthetic activation audit failure.');
            }
        });
        try {
            app(AcceptAdminInvitation::class)->handle($proof, (string) Str::uuid());
            self::fail('Expected rollback.');
        } catch (RuntimeException $problem) {
            self::assertSame('Synthetic activation audit failure.', $problem->getMessage());
        }
        $user = User::findOrFail($target['user_id']);
        self::assertSame($before, $user->getRawOriginal('password'));
        self::assertNull($user->email_verified_at);
        self::assertSame(1, $user->auth_version);
        self::assertNull(AdminInvitation::findOrFail($proof['invitation_id'])->consumed_at);
        self::assertNull(AdminProvisioning::query()->where('user_id', $user->id)->firstOrFail()->activated_at);
    }

    public function test_super_admin_does_not_bypass_ownership_or_revoked_and_late_sessions(): void
    {
        $super = $this->administrationActor();
        $other = $this->administrationActor('member');
        $profile = (new MemberProfile)->forceFill(['user_id' => $other->id]);
        self::assertFalse(app(MemberProfilePolicy::class)->view($super, $profile));
        $this->csrf();
        $this->login($super)->assertOk();
        $this->requestJson('GET', '/api/v1/me/account?user_id='.$other->id)->assertStatus(400);
        $row = DB::table('sessions')->where('user_id', $super->id)->first();
        self::assertNotNull($row);
        $cookies = $this->browserCookies;
        $super->forceFill(['auth_version' => 2])->save();
        DB::table('sessions')->where('id', $row->id)->delete();
        DB::table('sessions')->insert((array) $row);
        $this->browserCookies = $cookies;
        $this->requestJson('GET', '/api/v1/admin/landing-page')->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_EXPIRED');
    }

    public function test_blocked_unverified_and_pending_accounts_do_not_gain_privileges_from_super_role(): void
    {
        foreach (['blocked', 'unverified'] as $case) {
            $super = $this->administrationActor();
            $super->forceFill($case === 'blocked' ? ['status' => 'blocked'] : ['email_verified_at' => null])->save();
            self::assertFalse(AdministrativePermissions::manage($super));
            self::assertFalse(AdministrativePermissions::provision($super));
            try {
                $this->provisionAdmin($super);
                self::fail('Expected denial.');
            } catch (AuthorizationException) {
            }
        }
        $target = $this->provisionAdmin($this->administrationActor());
        $user = User::findOrFail($target['user_id']);
        $user->forceFill(['email_verified_at' => now('UTC')])->save();
        $user->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'), ['assigned_at' => now('UTC')]);
        self::assertFalse(AdministrativePermissions::provision($user));
    }

    public function test_normalized_email_and_rate_limit_do_not_create_duplicate_accounts(): void
    {
        $this->csrf();
        $this->login($this->administrationActor())->assertOk();
        $email = 'normalize-'.Str::uuid().'@example.test';
        $data = ['full_name' => '  Admin Uji  ', 'email' => '  '.strtoupper($email).'  ', 'request_key' => (string) Str::uuid()];
        $response = $this->requestJson('POST', '/api/v1/admin/accounts', $data)->assertCreated()
            ->assertJsonPath('data.full_name', 'Admin Uji')->assertJsonPath('data.email', $email);
        for ($i = 0; $i < 4; $i++) {
            $this->requestJson('POST', '/api/v1/admin/accounts', $data)->assertCreated();
        }
        $this->requestJson('POST', '/api/v1/admin/accounts', $data)->assertStatus(429)->assertHeader('Retry-After');
        self::assertSame(1, User::query()->where('email', $email)->count());
        self::assertSame(1, NotificationDelivery::query()->where('user_id', $response->json('data.user_id'))->count());
    }

    private function memberCounts(): array
    {
        return [
            DB::table('member_profiles')->count(), DB::table('memberships')->count(),
            DB::table('member_credentials')->count(), DB::table('member_number_sequences')->pluck('next_value', 'name')->all(),
        ];
    }
}
