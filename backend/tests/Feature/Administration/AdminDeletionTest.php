<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\AcceptAdminInvitation;
use App\Actions\Administration\CreateAdminAccount;
use App\Actions\Administration\DeleteAdminAccount;
use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AdministrationFixtures;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\TestCase;

final class AdminDeletionTest extends TestCase
{
    use AdministrationFixtures, VerificationBrowser;

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

    public function test_deletion_is_super_only_csrf_protected_and_strictly_validated(): void
    {
        $target = $this->administrationActor('admin');
        $path = '/api/v1/admin/accounts/'.$target->id;
        $this->csrf();
        $this->requestJson('DELETE', $path, rawBody: '{}')->assertUnauthorized();
        foreach (['admin', 'member', 'staff'] as $role) {
            $this->login($this->administrationActor($role))->assertOk();
            $this->requestJson('DELETE', $path, rawBody: '{}')->assertForbidden();
            $this->requestJson('POST', '/api/v1/auth/logout')->assertNoContent();
            $this->csrf();
        }
        $this->login($this->administrationActor())->assertOk();
        $this->requestJson('DELETE', $path, csrf: false, rawBody: '{}')->assertStatus(419);
        $this->requestJson('DELETE', $path, ['role' => 'admin'])->assertStatus(422);
        $this->requestJson('DELETE', $path.'?actor_id=1', rawBody: '{}')->assertStatus(422);
        $this->requestJson('DELETE', $path, rawBody: '[]')->assertStatus(400);
        self::assertSame(AccountStatus::Enabled, $target->fresh()->status);
        self::assertSame(1, $target->fresh()->roles()->count());
        $this->requestJson('DELETE', $path, rawBody: '{}')->assertNoContent();
        self::assertSame(AccountStatus::Blocked, $target->fresh()->status);
    }

    public function test_deletion_revokes_invites_delivery_and_sessions_and_preserves_email_and_history(): void
    {
        $actor = $this->administrationActor();
        $data = ['full_name' => 'Delete Synthetic Admin', 'email' => 'delete-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid()];
        $created = app(CreateAdminAccount::class)->handle($actor->id, 1, $data, (string) Str::uuid());
        $target = User::findOrFail($created['user_id']);
        $proof = [...$this->invitationProof($target->id), 'password' => 'New-test-password-123'];
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $password = $target->getRawOriginal('password');
        $memberships = DB::table('memberships')->count();
        $action = app(DeleteAdminAccount::class);
        $action->handle($actor->id, 1, $target->id, (string) Str::uuid());
        $action->handle($actor->id, 1, $target->id, (string) Str::uuid());
        self::assertSame(AccountStatus::Blocked, $target->fresh()->status);
        self::assertSame(2, $target->fresh()->auth_version);
        self::assertSame($password, $target->fresh()->getRawOriginal('password'));
        self::assertSame($data['email'], $target->fresh()->email);
        self::assertSame(0, $target->fresh()->roles()->count());
        self::assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        self::assertSame($memberships, DB::table('memberships')->count());
        self::assertNotNull(AdminInvitation::findOrFail($proof['invitation_id'])->revoked_at);
        $delivery = NotificationDelivery::query()->where('user_id', $target->id)->firstOrFail();
        self::assertSame('cancelled', $delivery->status->value);
        self::assertNull($delivery->payload_encrypted);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'admin.account_created')->where('target_id', $target->id)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'admin.account_deleted')->where('target_id', $target->id)->count());
        foreach (['accept', 'replay', 'email'] as $case) {
            try {
                match ($case) {
                    'accept' => app(AcceptAdminInvitation::class)->handle($proof, (string) Str::uuid()),
                    'replay' => app(CreateAdminAccount::class)->handle($actor->id, 1, $data, (string) Str::uuid()),
                    'email' => app(CreateAdminAccount::class)->handle($actor->id, 1, [...$data, 'request_key' => (string) Str::uuid()], (string) Str::uuid()),
                };
                self::fail('Deleted accounts must not be reactivated/recreated.');
            } catch (ApiProblem $problem) {
                self::assertSame(match ($case) {
                    'accept' => 'ADMIN_INVITATION_INVALID', 'replay' => 'ADMIN_ACCOUNT_DELETED', 'email' => 'EMAIL_UNAVAILABLE'
                }, $problem->errorCode);
            }
        }
        $this->csrf();
        $this->login($actor)->assertOk();
        $this->requestJson('GET', '/api/v1/admin/accounts')->assertOk()->assertJsonMissing(['user_id' => $target->id]);
        $this->requestJson('GET', '/api/v1/admin/accounts/'.$target->id.'/invitation')->assertNotFound();
    }

    public function test_self_super_admin_multi_role_membership_and_profile_targets_are_protected(): void
    {
        $actor = $this->administrationActor();
        $targets = [$actor, $this->administrationActor(), $this->administrationActor('member')];
        $multi = $this->administrationActor('admin');
        $multi->roles()->attach(Role::query()->where('code', 'staff')->value('id'), ['assigned_at' => now('UTC')]);
        $targets[] = $multi;
        $member = $this->administrationActor('admin');
        $member->membership()->save(new Membership);
        $targets[] = $member;
        $profile = $this->administrationActor('admin');
        $profile->memberProfile()->save((new MemberProfile)->forceFill([
            'full_name' => 'Synthetic protected profile', 'birth_date' => '2000-01-01', 'phone' => '080000000000', 'city' => 'Kota Uji',
        ]));
        $targets[] = $profile;
        foreach ($targets as $target) {
            $before = $target->fresh()->getRawOriginal();
            $roles = $target->roles()->count();
            try {
                app(DeleteAdminAccount::class)->handle($actor->id, 1, $target->id, (string) Str::uuid());
                self::fail('Expected protected account.');
            } catch (ApiProblem $problem) {
                self::assertSame('ADMIN_DELETE_NOT_ALLOWED', $problem->errorCode);
            }
            self::assertSame($before, $target->fresh()->getRawOriginal());
            self::assertSame($roles, $target->roles()->count());
        }
        $this->csrf();
        $this->login($actor)->assertOk();
        $rows = collect($this->requestJson('GET', '/api/v1/admin/accounts')->assertOk()->json('data.items'))->keyBy('user_id');
        foreach ([$multi, $member, $profile] as $protected) {
            self::assertFalse($rows[$protected->id]['can_delete']);
        }
    }

    public function test_active_admin_loses_login_and_late_saved_session_after_deletion(): void
    {
        $actor = $this->administrationActor();
        $target = $this->administrationActor('admin');
        $this->csrf();
        $this->login($target)->assertOk();
        $row = DB::table('sessions')->where('user_id', $target->id)->first();
        self::assertNotNull($row);
        app(DeleteAdminAccount::class)->handle($actor->id, 1, $target->id, (string) Str::uuid());
        DB::table('sessions')->insert((array) $row);
        $this->requestJson('GET', '/api/v1/auth/me')->assertForbidden();
        $this->csrf();
        $this->login($target)->assertUnauthorized();
    }

    public function test_audit_failure_rolls_back_deletion_and_revocations(): void
    {
        $actor = $this->administrationActor();
        $created = $this->provisionAdmin($actor);
        $target = User::findOrFail($created['user_id']);
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new RuntimeException('Synthetic deletion audit failure.');
            }
        });
        try {
            app(DeleteAdminAccount::class)->handle($actor->id, 1, $target->id, (string) Str::uuid());
            self::fail('Expected rollback.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic deletion audit failure.', $error->getMessage());
        }
        self::assertSame(AccountStatus::Enabled, $target->fresh()->status);
        self::assertSame(1, $target->fresh()->auth_version);
        self::assertSame(1, $target->roles()->count());
        self::assertNull(AdminInvitation::query()->where('user_id', $target->id)->firstOrFail()->revoked_at);
        self::assertNotNull(NotificationDelivery::query()->where('user_id', $target->id)->firstOrFail()->payload_encrypted);
    }

    public function test_deleted_account_endpoint_rejects_stale_blocked_unverified_and_revoked_actors(): void
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
            $this->requestJson('DELETE', '/api/v1/admin/accounts/'.$target->id, rawBody: '{}')->assertStatus($case === 'stale' ? 401 : 403);
            self::assertSame('enabled', $target->fresh()->status->value);
            self::assertSame(1, $target->roles()->count());
        }
    }

    public function test_deletion_rate_limit_also_applies_to_idempotent_retries(): void
    {
        $target = $this->administrationActor('admin');
        $this->csrf();
        $this->login($this->administrationActor())->assertOk();
        $path = '/api/v1/admin/accounts/'.$target->id;
        for ($i = 0; $i < 10; $i++) {
            $this->requestJson('DELETE', $path, rawBody: '{}')->assertNoContent();
        }
        $this->requestJson('DELETE', $path, rawBody: '{}')->assertStatus(429)->assertHeader('Retry-After');
        self::assertSame(2, $target->fresh()->auth_version);
    }
}
