<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\AcceptAdminInvitation;
use App\Actions\Administration\AcceptFirstAccountActivation;
use App\Actions\Administration\BootstrapSuperAdmin;
use App\Actions\Administration\IssueFirstAccountActivation;
use App\Actions\Auth\RequestPasswordReset;
use App\Actions\Auth\ResetMemberPassword;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AdminInvitationNotification;
use App\Services\Administration\AdministrativePermissions;
use App\Services\Notifications\LocalMailScope;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AdministrationFixtures;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\VerificationBrowser;
use Tests\TestCase;

final class FirstAccountActivationTest extends TestCase
{
    use AdministrationFixtures, VerificationBrowser;

    private User $candidate;

    private const ENDPOINT = '/api/v1/auth/first-account-activation/accept';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationDatabase();
        DB::beginTransaction();
        $this->seed(CoreRolesSeeder::class);
        Cache::store('array')->flush();
        config(['sanctum.stateful' => ['localhost:3000', 'localhost:8000'],
            'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class,
            'sorerunapp.frontend_url' => 'http://localhost:3000']);
        $this->candidate = User::factory()->unverified()->create(['email' => 'initial-'.Str::uuid().'@example.test']);
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

    private function issue(): array
    {
        $id = app(IssueFirstAccountActivation::class)->handle($this->candidate->id, $this->candidate->email, (string) Str::uuid());
        $delivery = NotificationDelivery::findOrFail($id);

        return ['invitation_id' => $delivery->payload_encrypted['invitation_id'], 'email' => $this->candidate->email,
            'token' => $delivery->payload_encrypted['token'], 'password' => 'Initial-test-password-123', 'password_confirmation' => 'Initial-test-password-123'];
    }

    public function test_activation_proves_email_but_grants_no_role_and_bootstrap_stays_separate(): void
    {
        $before = $this->memberState();
        $proof = $this->issue();
        $this->csrf();
        $this->requestJson('GET', self::ENDPOINT)->assertStatus(405);
        self::assertNull($this->candidate->fresh()->email_verified_at);
        $this->requestJson('POST', self::ENDPOINT, $proof)->assertOk()->assertJsonPath('data.result', 'first_account_activated')->assertJsonStructure(['meta' => ['request_id']]);
        $user = $this->candidate->fresh();
        self::assertNotNull($user->email_verified_at);
        self::assertTrue(Hash::check($proof['password'], $user->password));
        self::assertSame(2, $user->auth_version);
        self::assertFalse($user->roles()->exists());
        self::assertFalse(AdministrativePermissions::manage($user));
        self::assertFalse(AdministrativePermissions::provision($user));
        self::assertSame($before, $this->memberState());
        self::assertNull(DB::table('security_bootstraps')->where('name', 'first_super_admin')->value('completed_at'));
        $this->requestJson('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->requestJson('POST', self::ENDPOINT, $proof)->assertStatus(422)->assertJsonPath('error.code', 'FIRST_ACTIVATION_INVALID');
        $other = User::factory()->create();
        try {
            app(BootstrapSuperAdmin::class)->handle($other->id, $other->email, (string) Str::uuid());
            self::fail('A bound candidate cannot silently be replaced.');
        } catch (ApiProblem $problem) {
            self::assertSame('BOOTSTRAP_TARGET_INVALID', $problem->errorCode);
        }
        app(BootstrapSuperAdmin::class)->handle($user->id, $user->email, (string) Str::uuid());
        self::assertTrue(AdministrativePermissions::provision($user->fresh()));
        self::assertSame(3, $user->fresh()->auth_version);
        self::assertSame($before, $this->memberState());
        $this->login($user->fresh(), $proof['password'])->assertOk()->assertJsonPath('data.roles', ['super_admin']);
        $this->requestJson('GET', '/api/v1/admin/landing-page')->assertOk();
    }

    public function test_public_validation_csrf_extra_fields_and_rate_limit(): void
    {
        $proof = $this->issue();
        $this->csrf();
        $this->requestJson('POST', self::ENDPOINT, $proof, false)->assertStatus(419);
        foreach (['invitation_id', 'email', 'token'] as $field) {
            $invalid = $proof;
            unset($invalid[$field]);
            $this->requestJson('POST', self::ENDPOINT, $invalid)->assertStatus(422);
        }
        Cache::store('array')->flush();
        foreach ([['password' => 'short'], ['password_confirmation' => 'different'], ['role' => 'super_admin'], ['purpose' => 'admin_invitation'], ['user_id' => $this->candidate->id]] as $changes) {
            $this->requestJson('POST', self::ENDPOINT, [...$proof, ...$changes])->assertStatus(422);
        }
        $this->requestJson('POST', self::ENDPOINT, $proof)->assertStatus(429)->assertHeader('Retry-After');
        self::assertNull($this->candidate->fresh()->email_verified_at);
    }

    public static function invalidCases(): array
    {
        return array_combine($cases = ['token', 'expired', 'revoked', 'consumed', 'version', 'email', 'blocked', 'role', 'completed'], array_map(fn ($case) => [$case], $cases));
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_proof_never_changes_password_or_grants_permission(string $case): void
    {
        $proof = $this->issue();
        $invitation = AdminInvitation::findOrFail($proof['invitation_id']);
        match ($case) {
            'token' => $proof['token'] = str_repeat('0', 64),
            'expired' => $invitation->forceFill(['expires_at' => now('UTC')->subSecond()])->save(),
            'revoked' => $invitation->forceFill(['revoked_at' => now('UTC')])->save(),
            'consumed' => $invitation->forceFill(['consumed_at' => now('UTC')])->save(),
            'version' => $this->candidate->forceFill(['auth_version' => 2])->save(),
            'email' => $this->candidate->forceFill(['email' => 'changed-'.Str::uuid().'@example.test'])->save(),
            'blocked' => $this->candidate->forceFill(['status' => 'blocked'])->save(),
            'role' => $this->candidate->roles()->attach(Role::query()->where('code', 'admin')->value('id'), ['assigned_at' => now('UTC')]),
            'completed' => DB::table('security_bootstraps')->where('name', 'first_super_admin')->update(['completed_at' => now('UTC')]),
        };
        $before = $this->candidate->fresh()->getRawOriginal();
        $this->csrf();
        $this->requestJson('POST', self::ENDPOINT, $proof)->assertStatus(422)->assertJsonPath('error.code', 'FIRST_ACTIVATION_INVALID');
        self::assertSame($before, $this->candidate->fresh()->getRawOriginal());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'security.first_activation_accepted')->count());
    }

    public function test_resend_cooldown_old_proof_revocation_and_purpose_separation(): void
    {
        $old = $this->issue();
        try {
            $this->issue();
            self::fail('Cooldown is required even for CLI issuance.');
        } catch (ApiProblem $problem) {
            self::assertSame('FIRST_ACTIVATION_COOLDOWN', $problem->errorCode);
        }
        $this->travel(61)->seconds();
        $new = $this->issue();
        self::assertNotSame($old['token'], $new['token']);
        self::assertNotNull(AdminInvitation::findOrFail($old['invitation_id'])->revoked_at);
        $this->csrf();
        $this->requestJson('POST', self::ENDPOINT, $old)->assertStatus(422);
        $this->requestJson('POST', '/api/v1/auth/admin-invitations/accept', $new)->assertStatus(422)->assertJsonPath('error.code', 'ADMIN_INVITATION_INVALID');
        self::assertNull($this->candidate->fresh()->email_verified_at);
        $this->requestJson('POST', self::ENDPOINT, $new)->assertOk();
    }

    public function test_regular_admin_proof_cannot_be_accepted_as_initial_activation(): void
    {
        $actor = $this->administrationActor();
        $target = $this->provisionAdmin($actor);
        $actor->roles()->detach();
        $proof = [...$this->invitationProof($target['user_id']), 'password' => 'New-test-password-123'];
        try {
            app(AcceptFirstAccountActivation::class)->handle($proof, (string) Str::uuid());
            self::fail('Purposes must never be interchangeable.');
        } catch (ApiProblem $problem) {
            self::assertSame('FIRST_ACTIVATION_INVALID', $problem->errorCode);
        }
        app(AcceptAdminInvitation::class)->handle($proof, (string) Str::uuid());
        self::assertNotNull(User::findOrFail($target['user_id'])->email_verified_at);
    }

    public function test_initial_issuance_cannot_switch_candidate_or_bypass_bootstrap_eligibility(): void
    {
        $proof = $this->issue();
        $other = User::factory()->unverified()->create();
        foreach ([[$other->id, $other->email], [$this->candidate->id, 'wrong@example.test']] as [$id, $email]) {
            try {
                app(IssueFirstAccountActivation::class)->handle($id, $email, (string) Str::uuid());
                self::fail('Explicit target/fence mismatch must be rejected.');
            } catch (ApiProblem $problem) {
                self::assertSame('FIRST_ACTIVATION_NOT_AVAILABLE', $problem->errorCode);
            }
        }
        try {
            app(BootstrapSuperAdmin::class)->handle($this->candidate->id, $this->candidate->email, (string) Str::uuid());
            self::fail('An unverified candidate cannot bootstrap.');
        } catch (ApiProblem $problem) {
            self::assertSame('BOOTSTRAP_TARGET_INVALID', $problem->errorCode);
        }
        $migration = require database_path('migrations/2026_11_21_180000_support_first_account_activation.php');
        try {
            $migration->down();
            self::fail('Rollback cannot delete activation history.');
        } catch (RuntimeException $problem) {
            self::assertStringContainsString('Initial activation history exists', $problem->getMessage());
        }
        self::assertNotNull(AdminInvitation::find($proof['invitation_id']));
        self::assertNull($this->candidate->fresh()->email_verified_at);
    }

    public function test_initial_issuance_limit_is_serialized_by_the_user_lock(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->issue();
            $this->travel(61)->seconds();
        }
        try {
            $this->issue();
            self::fail('Sixth issuance within an hour must fail.');
        } catch (ApiProblem $problem) {
            self::assertSame('FIRST_ACTIVATION_RATE_LIMIT', $problem->errorCode);
        }
        self::assertSame(5, AdminInvitation::query()->where('user_id', $this->candidate->id)->count());
    }

    public function test_issuance_audit_failure_rolls_back_fence_invitation_and_outbox(): void
    {
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new RuntimeException('Synthetic initial issuance audit failure.');
            }
        });
        try {
            $this->issue();
            self::fail('Expected issuance rollback.');
        } catch (RuntimeException $problem) {
            self::assertSame('Synthetic initial issuance audit failure.', $problem->getMessage());
        }
        self::assertFalse(AdminInvitation::query()->where('user_id', $this->candidate->id)->exists());
        self::assertFalse(NotificationDelivery::query()->where('user_id', $this->candidate->id)->exists());
        self::assertNull(DB::table('security_bootstraps')->where('name', 'first_super_admin')->value('user_id'));
    }

    public function test_reset_cannot_verify_initial_candidate_and_revokes_activation_proof(): void
    {
        $proof = $this->issue();
        app(RequestPasswordReset::class)->handle($this->candidate->email);
        $reset = NotificationDelivery::query()->where('user_id', $this->candidate->id)->where('type', 'password_reset')->firstOrFail();
        app(ResetMemberPassword::class)->handle(['email' => $this->candidate->email, 'token' => $reset->payload_encrypted['token'], 'password' => 'Reset-test-password-123'], (string) Str::uuid());
        self::assertNull($this->candidate->fresh()->email_verified_at);
        self::assertNotNull(AdminInvitation::findOrFail($proof['invitation_id'])->revoked_at);
        self::assertNull(NotificationDelivery::query()->where('user_id', $this->candidate->id)->where('type', 'first_account_activation')->firstOrFail()->payload_encrypted);
        $this->csrf();
        $this->requestJson('POST', self::ENDPOINT, $proof)->assertStatus(422);
        $this->login($this->candidate->fresh(), 'Reset-test-password-123')->assertForbidden();
    }

    public function test_other_browser_identity_is_never_replaced(): void
    {
        $proof = $this->issue();
        $other = $this->administrationActor('member');
        $this->csrf();
        $this->login($other)->assertOk();
        $this->requestJson('POST', self::ENDPOINT, $proof)->assertForbidden()->assertJsonPath('error.code', 'INVITATION_SESSION_CONFLICT');
        $this->requestJson('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $other->id);
        self::assertNull($this->candidate->fresh()->email_verified_at);
    }

    public function test_audit_failure_rolls_back_verification_password_sessions_and_proof(): void
    {
        $proof = $this->issue();
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $this->candidate->id, 'payload' => '', 'last_activity' => time()]);
        $before = $this->candidate->fresh()->getRawOriginal();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new RuntimeException('Synthetic initial activation audit failure.');
            }
        });
        try {
            app(AcceptFirstAccountActivation::class)->handle($proof, (string) Str::uuid());
            self::fail('Expected audit failure.');
        } catch (RuntimeException $problem) {
            self::assertSame('Synthetic initial activation audit failure.', $problem->getMessage());
        }
        self::assertSame($before, $this->candidate->fresh()->getRawOriginal());
        self::assertNull(AdminInvitation::findOrFail($proof['invitation_id'])->consumed_at);
        self::assertSame(1, DB::table('sessions')->where('user_id', $this->candidate->id)->count());
    }

    public function test_local_non_synthetic_exception_is_bound_to_initial_proof_not_general_mail(): void
    {
        $this->candidate->forceFill(['email' => 'initial-local@sorerun.invalid'])->save();
        $proof = $this->issue();
        $delivery = NotificationDelivery::query()->where('user_id', $this->candidate->id)->firstOrFail();
        self::assertTrue(app(LocalMailScope::class)->allows($delivery));
        self::assertStringNotContainsString($proof['token'], $delivery->getRawOriginal('payload_encrypted'));
        $notification = app(AdminInvitationNotification::class)->forDelivery($delivery, $this->candidate);
        self::assertNotNull($notification);
        self::assertStringContainsString('/activate-first-account#', $notification->toMail($this->candidate)->actionUrl);
        $delivery->type = 'password_reset';
        self::assertFalse(app(LocalMailScope::class)->allows($delivery));
        $this->candidate->forceFill(['auth_version' => 2])->save();
        $delivery->type = 'first_account_activation';
        self::assertNull(app(AdminInvitationNotification::class)->forDelivery($delivery, $this->candidate));
    }

    private function memberState(): array
    {
        return [DB::table('member_profiles')->count(), DB::table('memberships')->count(), DB::table('member_credentials')->count(), DB::table('member_number_sequences')->pluck('next_value', 'name')->all()];
    }
}
