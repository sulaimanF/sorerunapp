<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\AcceptAdminInvitation;
use App\Actions\Administration\AcceptFirstAccountActivation;
use App\Actions\Administration\IssueFirstAccountActivation;
use App\Actions\Auth\RequestPasswordReset;
use App\Models\AdminInvitation;
use App\Models\AdminProvisioning;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\AdministrationCommittedFixtures;
use Tests\TestCase;

final class AdminConcurrencyTest extends TestCase
{
    use AdministrationCommittedFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationDatabase();
        $this->seed(CoreRolesSeeder::class);
        foreach ([1, 2] as $_) {
            $this->administrationActors[] = $this->administrationActor()->id;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupAdministrationFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_super_admins_claiming_one_email_create_one_account(): void
    {
        $email = 'stage11-race-'.Str::uuid().'@example.test';
        $tasks = [];
        foreach ($this->administrationActors as $actor) {
            $tasks[] = [
                'operation' => 'create', 'actor' => $actor,
                'data' => ['full_name' => 'Concurrent Admin', 'email' => $email, 'request_key' => (string) Str::uuid()],
            ];
        }
        $results = $this->race($tasks);
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['EMAIL_UNAVAILABLE', 'created'], $codes);
        self::assertSame(1, User::query()->where('email', $email)->count());
        self::assertSame(1, AdminProvisioning::query()->whereIn('created_by', $this->administrationActors)->count());
    }

    public function test_parallel_same_idempotency_key_has_one_outbox_and_one_account(): void
    {
        $actor = $this->administrationActors[0];
        $task = ['operation' => 'create', 'actor' => $actor, 'data' => [
            'full_name' => 'Concurrent Admin', 'email' => 'stage11-idempotent-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid(),
        ]];
        $results = $this->race([[...$task, 'hold_user' => $actor], [...$task, 'wait_held' => true]]);
        self::assertSame($results[0]['user_id'], $results[1]['user_id']);
        self::assertSame(1, NotificationDelivery::query()->where('user_id', $results[0]['user_id'])->count());
    }

    public function test_parallel_acceptance_consumes_once(): void
    {
        $target = $this->provisionAdmin(User::findOrFail($this->administrationActors[0]));
        $proof = [...$this->invitationProof($target['user_id']), 'password' => 'New-test-password-123'];
        $results = $this->race([
            ['operation' => 'accept', 'proof' => $proof, 'hold_user' => $target['user_id']],
            ['operation' => 'accept', 'proof' => $proof, 'wait_held' => true],
        ]);
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['ADMIN_INVITATION_INVALID', 'accepted'], $codes);
        self::assertSame(2, User::findOrFail($target['user_id'])->auth_version);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'admin.invitation_accepted')->where('target_id', $target['user_id'])->count());
    }

    public function test_parallel_admin_deletions_have_one_audit_and_version_change(): void
    {
        $actor = $this->administrationActors[0];
        $target = $this->provisionAdmin(User::findOrFail($actor));
        $task = ['operation' => 'delete', 'actor' => $actor, 'target' => $target['user_id']];
        $results = $this->race([[...$task, 'hold_user' => $actor], [...$task, 'wait_held' => true]]);
        self::assertSame(['deleted', 'deleted'], array_column($results, 'code'));
        self::assertSame(2, User::findOrFail($target['user_id'])->auth_version);
        self::assertSame(0, User::findOrFail($target['user_id'])->roles()->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'admin.account_deleted')->where('target_id', $target['user_id'])->count());
    }

    public function test_delete_winning_lock_blocks_invitation_acceptance_and_resend(): void
    {
        foreach (['accept', 'resend'] as $operation) {
            $actor = $this->administrationActors[0];
            $target = $this->provisionAdmin(User::findOrFail($actor));
            $proof = [...$this->invitationProof($target['user_id']), 'password' => 'New-test-password-123'];
            $results = $this->race([
                ['operation' => 'delete', 'actor' => $actor, 'target' => $target['user_id'], 'hold_users' => [$actor, $target['user_id']]],
                ['operation' => $operation, 'actor' => $actor, 'target' => $target['user_id'], 'proof' => $proof, 'wait_held' => true],
            ]);
            self::assertSame(['deleted', $operation === 'accept' ? 'ADMIN_INVITATION_INVALID' : 'INVITATION_NOT_AVAILABLE'], array_column($results, 'code'));
            self::assertNull(AdminProvisioning::query()->where('user_id', $target['user_id'])->firstOrFail()->activated_at);
            self::assertSame(2, User::findOrFail($target['user_id'])->auth_version);
        }
    }

    public function test_activation_winning_lock_is_followed_by_deletion_without_restoring_access(): void
    {
        $actor = $this->administrationActors[0];
        $target = $this->provisionAdmin(User::findOrFail($actor));
        $proof = [...$this->invitationProof($target['user_id']), 'password' => 'New-test-password-123'];
        $results = $this->race([
            ['operation' => 'accept', 'proof' => $proof, 'hold_user' => $target['user_id']],
            ['operation' => 'delete', 'actor' => $actor, 'target' => $target['user_id'], 'wait_held' => true],
        ]);
        self::assertSame(['accepted', 'deleted'], array_column($results, 'code'));
        $user = User::findOrFail($target['user_id']);
        self::assertSame('blocked', $user->status->value);
        self::assertSame(3, $user->auth_version);
        self::assertSame(0, $user->roles()->count());
    }

    public function test_parallel_key_reuse_with_different_payload_is_a_conflict(): void
    {
        $actor = $this->administrationActors[0];
        $data = ['full_name' => 'First Admin', 'email' => 'stage11-key-race-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid()];
        $results = $this->race([
            ['operation' => 'create', 'actor' => $actor, 'data' => $data, 'hold_user' => $actor],
            ['operation' => 'create', 'actor' => $actor, 'data' => [...$data, 'full_name' => 'Different Admin'], 'wait_held' => true],
        ]);
        self::assertSame(['created', 'IDEMPOTENCY_CONFLICT'], array_column($results, 'code'));
        self::assertSame(1, User::query()->where('email', $data['email'])->count());
    }

    public function test_resend_winning_lock_makes_old_proof_unusable(): void
    {
        $actor = $this->administrationActors[0];
        $target = $this->provisionAdmin(User::findOrFail($actor));
        $proof = [...$this->invitationProof($target['user_id']), 'password' => 'New-test-password-123'];
        AdminInvitation::query()->where('user_id', $target['user_id'])->update(['created_at' => now('UTC')->subMinutes(2)]);
        $results = $this->race([
            ['operation' => 'resend', 'actor' => $actor, 'target' => $target['user_id'], 'hold_user' => $actor],
            ['operation' => 'accept', 'proof' => $proof, 'wait_held' => true],
        ]);
        // Both legal serial orders are accepted: activation first prevents resend; resend first rejects old proof.
        self::assertContains(array_column($results, 'code'), [
            ['resent', 'ADMIN_INVITATION_INVALID'], ['INVITATION_NOT_AVAILABLE', 'accepted'],
        ]);
        self::assertLessThanOrEqual(1, AdminInvitation::query()->where('user_id', $target['user_id'])->whereNotNull('consumed_at')->count());
    }

    public function test_reset_racing_activation_cannot_revalidate_old_invite(): void
    {
        $target = $this->provisionAdmin(User::findOrFail($this->administrationActors[0]));
        $user = User::findOrFail($target['user_id']);
        $invite = [...$this->invitationProof($user->id), 'password' => 'Invitation-test-password-123'];
        app(RequestPasswordReset::class)->handle($user->email);
        $reset = NotificationDelivery::query()->where('user_id', $user->id)->where('type', 'password_reset')->firstOrFail()->payload_encrypted;
        $results = $this->race([
            ['operation' => 'reset', 'proof' => ['email' => $user->email, 'token' => $reset['token'], 'password' => 'Reset-test-password-123'], 'hold_user' => $user->id],
            ['operation' => 'accept', 'proof' => $invite, 'wait_held' => true],
        ]);
        self::assertSame(['reset', 'ADMIN_INVITATION_INVALID'], array_column($results, 'code'));
        self::assertNull($user->fresh()->email_verified_at);
        self::assertNull(AdminProvisioning::query()->where('user_id', $user->id)->firstOrFail()->activated_at);
    }

    public function test_parallel_bootstrap_different_explicit_accounts_has_one_winner(): void
    {
        foreach ($this->administrationActors as $id) {
            User::findOrFail($id)->roles()->detach();
        }
        if (DB::table('security_bootstraps')->whereNotNull('completed_at')->exists()
            || DB::table('user_roles')->whereIn('role_id', Role::query()->where('code', 'super_admin')->select('id'))->exists()) {
            throw new RuntimeException('Bootstrap race refuses an already initialized shared test fence.');
        }
        $tasks = [];
        foreach ($this->administrationActors as $id) {
            $tasks[] = ['operation' => 'bootstrap', 'target' => $id, 'email' => User::findOrFail($id)->email];
        }
        $results = $this->race($tasks);
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['BOOTSTRAP_ALREADY_COMPLETED', 'bootstrapped'], $codes);
        self::assertSame(1, DB::table('user_roles')->whereIn('user_id', $this->administrationActors)->count());
    }

    public function test_role_revocation_committed_first_prevents_parallel_provisioning(): void
    {
        $actor = $this->administrationActors[0];
        $email = 'stage11-revoked-'.Str::uuid().'@example.test';
        $results = $this->race([
            ['operation' => 'revoke', 'actor' => $actor, 'hold_user' => $actor],
            ['operation' => 'create', 'actor' => $actor, 'data' => ['full_name' => 'Denied Admin', 'email' => $email, 'request_key' => (string) Str::uuid()], 'wait_held' => true],
        ]);
        self::assertSame(['revoked', 'FORBIDDEN'], array_column($results, 'code'));
        self::assertFalse(User::query()->where('email', $email)->exists());
    }

    public function test_first_issuance_parallel_different_candidates_binds_only_one_account(): void
    {
        $candidates = $this->initialCandidates();
        $results = $this->race(array_map(fn (User $user) => ['operation' => 'issue-first', 'target' => $user->id, 'email' => $user->email], $candidates));
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['FIRST_ACTIVATION_NOT_AVAILABLE', 'issued'], $codes);
        self::assertSame(1, AdminInvitation::query()->whereIn('user_id', $this->administrationActors)->count());
        self::assertSame(0, DB::table('user_roles')->whereIn('user_id', $this->administrationActors)->count());
    }

    public function test_parallel_initial_acceptance_consumes_one_proof_and_never_grants_a_role(): void
    {
        $user = $this->initialCandidates()[0];
        $proof = $this->firstProof($user);
        $results = $this->race([
            ['operation' => 'accept-first', 'proof' => $proof, 'hold_user' => $user->id],
            ['operation' => 'accept-first', 'proof' => $proof, 'wait_held' => true],
        ]);
        self::assertSame(['activated', 'FIRST_ACTIVATION_INVALID'], array_column($results, 'code'));
        self::assertSame(2, $user->fresh()->auth_version);
        self::assertFalse($user->roles()->exists());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'security.first_activation_accepted')->where('target_id', $user->id)->count());
    }

    public function test_parallel_initial_issuance_same_candidate_has_one_outbox(): void
    {
        $user = $this->initialCandidates()[0];
        $task = ['operation' => 'issue-first', 'target' => $user->id, 'email' => $user->email];
        $results = $this->race([[...$task, 'hold_user' => $user->id], [...$task, 'wait_held' => true]]);
        self::assertSame(['issued', 'FIRST_ACTIVATION_COOLDOWN'], array_column($results, 'code'));
        self::assertSame(1, NotificationDelivery::query()->where('user_id', $user->id)->count());
        self::assertSame(1, AdminInvitation::query()->where('user_id', $user->id)->count());
    }

    public function test_initial_resend_winning_lock_revokes_old_proof_before_parallel_accept(): void
    {
        $user = $this->initialCandidates()[0];
        $proof = $this->firstProof($user);
        AdminInvitation::query()->where('user_id', $user->id)->update(['created_at' => now('UTC')->subMinutes(2)]);
        $results = $this->race([
            ['operation' => 'issue-first', 'target' => $user->id, 'email' => $user->email, 'hold_user' => $user->id],
            ['operation' => 'accept-first', 'proof' => $proof, 'wait_held' => true],
        ]);
        self::assertSame(['issued', 'FIRST_ACTIVATION_INVALID'], array_column($results, 'code'));
        self::assertNull($user->fresh()->email_verified_at);
        self::assertSame(1, AdminInvitation::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_reset_winning_initial_activation_lock_never_verifies_the_account(): void
    {
        $user = $this->initialCandidates()[0];
        $proof = $this->firstProof($user);
        app(RequestPasswordReset::class)->handle($user->email);
        $reset = NotificationDelivery::query()->where('user_id', $user->id)->where('type', 'password_reset')->firstOrFail()->payload_encrypted;
        $results = $this->race([
            ['operation' => 'reset', 'proof' => ['email' => $user->email, 'token' => $reset['token'], 'password' => 'Reset-test-password-123'], 'hold_user' => $user->id],
            ['operation' => 'accept-first', 'proof' => $proof, 'wait_held' => true],
        ]);
        self::assertSame(['reset', 'FIRST_ACTIVATION_INVALID'], array_column($results, 'code'));
        self::assertNull($user->fresh()->email_verified_at);
        self::assertFalse($user->roles()->exists());
    }

    public function test_parallel_bootstrap_after_initial_activation_has_one_assignment(): void
    {
        $user = $this->initialCandidates()[0];
        app(AcceptFirstAccountActivation::class)->handle($this->firstProof($user), (string) Str::uuid());
        $task = ['operation' => 'bootstrap', 'target' => $user->id, 'email' => $user->email];
        $results = $this->race([[...$task, 'hold_user' => $user->id], [...$task, 'wait_held' => true]]);
        self::assertSame(['bootstrapped', 'BOOTSTRAP_ALREADY_COMPLETED'], array_column($results, 'code'));
        self::assertSame(1, $user->roles()->count());
        self::assertSame(3, $user->fresh()->auth_version);
    }

    private function initialCandidates(): array
    {
        return array_map(function (int $id): User {
            $user = User::findOrFail($id);
            $user->roles()->detach();
            $user->forceFill(['email_verified_at' => null])->save();

            return $user;
        }, $this->administrationActors);
    }

    private function firstProof(User $user): array
    {
        $id = app(IssueFirstAccountActivation::class)->handle($user->id, $user->email, (string) Str::uuid());
        $proof = NotificationDelivery::findOrFail($id)->payload_encrypted;

        return ['invitation_id' => $proof['invitation_id'], 'email' => $user->email, 'token' => $proof['token'], 'password' => 'Initial-test-password-123'];
    }

    public function test_parallel_own_password_changes_reject_the_stale_reauthentication(): void
    {
        $actor = $this->administrationActors[0];
        $task = ['operation' => 'password', 'actor' => $actor, 'data' => ['current_password' => 'Test-only-password-123', 'password' => 'Parallel-new-password-123']];
        $results = $this->race([[...$task, 'hold_user' => $actor], [...$task, 'wait_held' => true]]);
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['SESSION_EXPIRED', 'password_changed'], $codes);
        self::assertSame(2, User::findOrFail($actor)->auth_version);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'auth.password_changed')->where('target_id', $actor)->count());
    }

    public function test_delete_winning_lock_rejects_password_change_without_reactivating_admin(): void
    {
        $actor = $this->administrationActors[0];
        $target = $this->provisionAdmin(User::findOrFail($actor));
        app(AcceptAdminInvitation::class)->handle([...$this->invitationProof($target['user_id']), 'password' => 'Target-test-password-123'], (string) Str::uuid());
        $results = $this->race([
            ['operation' => 'delete', 'actor' => $actor, 'target' => $target['user_id'], 'hold_users' => [$actor, $target['user_id']]],
            ['operation' => 'password', 'actor' => $actor, 'target' => $target['user_id'], 'data' => ['current_password' => 'Test-only-password-123', 'password' => 'Rejected-new-password-123'], 'wait_held' => true],
        ]);
        self::assertSame(['deleted', 'ADMIN_PASSWORD_NOT_ALLOWED'], array_column($results, 'code'));
        self::assertSame('blocked', User::findOrFail($target['user_id'])->status->value);
    }

    private function race(array $tasks): array
    {
        $run = bin2hex(random_bytes(16));
        $directory = storage_path('framework/testing/administration-race-'.$run);
        if (! mkdir($directory, 0775, true)) {
            throw new RuntimeException('Cannot create barrier.');
        }
        $processes = [];
        try {
            foreach ($tasks as $i => $task) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/administration-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => str_repeat('t', 32),
                    'APP_CONFIG_CACHE' => base_path('bootstrap/cache/stage11-testing-config.php'),
                    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'sorerunapp_stage11_test', 'DB_URL' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                    'OUTBOX_DISPATCH_ON_COMMIT' => 'false', 'LOG_CHANNEL' => 'stderr', 'BCRYPT_ROUNDS' => '4',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => $i + 1, 'task' => $task], JSON_THROW_ON_ERROR));
                $process->setTimeout(40);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 25;
            while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
                clearstatcache();
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Barrier timed out.');
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Worker exited before barrier.');
                    }
                }
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), 'Race worker failed; inspect sanitized technical error.');
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertNotSame($results[0]['connection'], $results[1]['connection']);

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (['ready-1', 'ready-2', 'go', 'held', 'entering'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }
}
