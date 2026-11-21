<?php

declare(strict_types=1);

use App\Actions\Administration\CreateAdminAccount;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdministrationCommittedFixtures;

require __DIR__.'/stage11-environment.php';
bootStage11();
$actor = null;
$needsRestore = false;
$migration = require database_path('migrations/2026_11_21_180000_support_first_account_activation.php');
try {
    if (DB::table('admin_invitations')->where('purpose', 'first_account_activation')->exists()
        || DB::table('security_bootstraps')->whereNotNull('user_id')->exists()) {
        throw new RuntimeException('Refuses activation history or a bound test fence; no reset is performed.');
    }
    (new CoreRolesSeeder)->run();
    $actor = User::factory()->create(['email' => 'migration-'.Str::uuid().'@example.test']);
    $actor->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'), ['assigned_at' => now('UTC')]);
    $target = app(CreateAdminAccount::class)->handle($actor->id, 1, ['full_name' => 'Migration Test Admin', 'email' => 'migration-target-'.Str::uuid().'@example.test', 'request_key' => (string) Str::uuid()], (string) Str::uuid());
    $before = (array) DB::table('admin_invitations')->where('user_id', $target['user_id'])->first();
    $pivot = DB::table('user_roles')->where('user_id', $target['user_id'])->get()->toJson();
    $migration->down();
    $needsRestore = true;
    $old = $before;
    unset($old['purpose']);
    if ((array) DB::table('admin_invitations')->where('id', $before['id'])->first() !== $old) {
        throw new RuntimeException('Existing Admin invitation changed on schema rollback.');
    }
    $migration->up();
    $needsRestore = false;
    if ((array) DB::table('admin_invitations')->where('id', $before['id'])->first() !== $before
        || DB::table('user_roles')->where('user_id', $target['user_id'])->get()->toJson() !== $pivot) {
        throw new RuntimeException('Existing Admin invitation/role pivot changed on upgrade.');
    }
    foreach ([['purpose' => 'password_reset'], ['purpose' => 'admin_invitation', 'created_by' => null], ['purpose' => 'first_account_activation', 'created_by' => $actor->id]] as $invalid) {
        try {
            DB::table('admin_invitations')->insert([...$before, ...$invalid, 'id' => (string) Str::uuid()]);
            throw new RuntimeException('Purpose/issuer constraint was not enforced.');
        } catch (QueryException $problem) {
            if ((int) ($problem->errorInfo[1] ?? 0) !== 3819) {
                throw new RuntimeException('Unexpected constraint error.');
            }
        }
    }
    echo json_encode(['database' => 'sorerunapp_stage11_test', 'admin_invitation_preserved' => true, 'pivot_preserved' => true, 'purpose_issuer_checks_enforced' => true, 'empty_history_rollback_upgrade' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $problem) {
    fwrite(STDERR, 'Initial activation migration verification failed: '.get_class($problem).PHP_EOL);
    exit(1);
} finally {
    if ($needsRestore) {
        $migration->up();
    }
    if ($actor !== null) {
        (new class
        {
            use AdministrationCommittedFixtures;

            public function clean(int $id): void
            {
                $this->administrationActors = [$id];
                $this->cleanupAdministrationFixtures();
            }
        })->clean($actor->id);
    }
}
