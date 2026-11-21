<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$flags = array_slice($argv, 1);
if (array_diff($flags, ['--inspect', '--create', '--baseline', '--migrate']) !== []) {
    throw new RuntimeException('Only --inspect/--create/--baseline/--migrate are supported.');
}
require __DIR__.'/stage11-environment.php';
bootStage11(create: in_array('--create', $flags, true));
if (in_array('--baseline', $flags, true)) {
    if (DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
        throw new RuntimeException('Baseline is allowed only on an empty Stage11 test schema; no reset is performed.');
    }
    foreach (glob(database_path('migrations/*.php')) as $file) {
        if (basename($file) >= '2026_11_21_120000_add_super_admin_and_admin_invitations.php') {
            continue;
        }
        $exit = Artisan::call('migrate', ['--path' => 'database/migrations/'.basename($file), '--force' => true]);
        if ($exit !== 0) {
            throw new RuntimeException('Baseline migration failed: '.basename($file));
        }
    }
}
if (! Schema::hasColumn('notification_deliveries', 'payload_encrypted') || ! Schema::hasTable('landing_pages')) {
    throw new RuntimeException('Review/apply Stage01-10 baseline on this dedicated test database first.');
}
if (in_array('--migrate', $flags, true)) {
    $exit = Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_21_120000_add_super_admin_and_admin_invitations.php', '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('Stage11 test migration failed; inspect status.');
    }
    $exit = Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_21_180000_support_first_account_activation.php', '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('Initial activation migration failed; inspect status.');
    }
}
echo json_encode([
    'environment' => app()->environment(), 'database' => DB::selectOne('SELECT DATABASE() AS n')->n,
    'version' => DB::selectOne('SELECT VERSION() AS n')->n,
    'schema_ready' => Schema::hasTable('admin_provisionings') && Schema::hasTable('admin_invitations') && Schema::hasTable('security_bootstraps'),
    'migration' => DB::table('migrations')->where('migration', '2026_11_21_120000_add_super_admin_and_admin_invitations')->first(['migration', 'batch']),
    'activation_migration' => DB::table('migrations')->where('migration', '2026_11_21_180000_support_first_account_activation')->first(['migration', 'batch']),
], JSON_THROW_ON_ERROR).PHP_EOL;
