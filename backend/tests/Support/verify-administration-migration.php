<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/stage11-environment.php';
bootStage11();
if (Schema::hasTable('admin_provisionings') || DB::table('users')->exists() || DB::table('roles')->exists()) {
    throw new RuntimeException('Migration verification requires the newly prepared pre-Stage11 schema without accounts/roles.');
}
$now = now('UTC');
foreach (['member', 'staff', 'admin'] as $role) {
    DB::table('roles')->insert(['code' => $role, 'name' => $role, 'created_at' => $now, 'updated_at' => $now]);
}
$id = DB::table('users')->insertGetId(['name' => 'Migration Synthetic', 'email' => 'stage11-migration-'.Str::uuid().'@example.test', 'password' => Hash::make(Str::random(32)), 'email_verified_at' => $now]);
$roleId = DB::table('roles')->where('code', 'admin')->value('id');
DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId, 'assigned_by' => null, 'assigned_at' => $now]);
$before = DB::table('roles')->orderBy('id')->get()->toJson();
$assert = static function (bool $ok, string $label): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
};
$path = 'database/migrations/2026_11_21_120000_add_super_admin_and_admin_invitations.php';
$assert(Artisan::call('migrate', ['--path' => $path, '--force' => true]) === 0, 'Stage11 migrate failed.');
$assert(DB::table('users')->count() === 1, 'Migration created an account.');
$assert(DB::table('user_roles')->where('user_id', $id)->where('role_id', $roleId)->exists(), 'Existing pivot changed.');
$assert(DB::table('roles')->where('code', 'super_admin')->count() === 1, 'Super role missing.');
foreach (['admin_provisionings', 'admin_invitations', 'security_bootstraps'] as $table) {
    $assert(Schema::hasTable($table), 'Missing table.');
}
try {
    DB::table('roles')->insert(['code' => 'unknown_role', 'name' => 'Synthetic']);
    throw new RuntimeException('Unknown role unexpectedly accepted.');
} catch (QueryException $e) {
    $assert((int) ($e->errorInfo[1] ?? 0) === 3819, 'Unexpected CHECK error.');
}
$migration = require database_path('migrations/'.basename($path));
$migration->down();
$assert(DB::table('roles')->orderBy('id')->get()->toJson() === $before, 'Rollback changed existing roles.');
$assert(DB::table('users')->count() === 1 && DB::table('user_roles')->where('user_id', $id)->exists(), 'Rollback removed existing account/pivot.');
$migration->up();
DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'super_admin')->value('id'), 'assigned_by' => null, 'assigned_at' => $now]);
try {
    $migration->down();
    throw new LogicException('Rollback accepted assigned Super Admin.');
} catch (RuntimeException $e) {
    $assert(! $e instanceof LogicException && str_contains($e->getMessage(), 'Administrative data exists'), 'Unexpected rollback refusal.');
}
$assert(Schema::hasTable('admin_provisionings') && DB::table('user_roles')->where('user_id', $id)->count() === 2, 'Refused rollback mutated data.');
DB::table('user_roles')->where('user_id', $id)->delete();
DB::table('users')->where('id', $id)->delete();
echo json_encode(['database' => 'sorerunapp_stage11_test', 'migration' => 'applied', 'baseline_roles_and_pivot_preserved' => true, 'no_default_account' => true, 'unknown_role_rejected' => true, 'empty_rollback_preserved_baseline' => true, 'assigned_role_rollback_refused' => true, 'batch' => DB::table('migrations')->where('migration', basename($path, '.php'))->value('batch')], JSON_THROW_ON_ERROR).PHP_EOL;
