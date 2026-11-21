<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/stage10-environment.php';
try {
    bootStage10();
    $identity = DB::selectOne('SELECT DATABASE() AS db_name, VERSION() AS version');
    echo json_encode([
        'environment' => app()->environment(), 'database' => $identity->db_name, 'mysql' => $identity->version,
        'gd' => extension_loaded('gd'), 'webp' => function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0,
        'memory' => ini_get('memory_limit'), 'upload' => ini_get('upload_max_filesize'), 'post' => ini_get('post_max_size'),
        'mail' => config('mail.default'), 'cache' => config('cache.default'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    if (($argv[1] ?? '') === '--inspect') {
        $tables = ['landing_pages', 'landing_page_revisions', 'landing_page_media', 'landing_page_revision_media'];
        $application = Dotenv\Dotenv::parse(file_get_contents(base_path('.env')));
        echo json_encode([
            'migration' => DB::table('migrations')->where('migration', '2026_11_14_120000_create_landing_page_content_tables')->first(['migration', 'batch']),
            'test_tables' => DB::table('information_schema.tables')->where('table_schema', 'sorerunapp_test')->whereIn('table_name', $tables)->get(['TABLE_NAME']),
            'constraints' => DB::table('information_schema.table_constraints')->where('table_schema', 'sorerunapp_test')->whereIn('table_name', $tables)->get(['table_name', 'constraint_name', 'constraint_type']),
            'application_landing_tables' => DB::table('information_schema.tables')->where('table_schema', $application['DB_DATABASE'])->whereIn('table_name', $tables)->count(),
            'draft_version' => DB::table('landing_pages')->where('id', 1)->value('draft_version'),
            'revisions' => DB::table('landing_page_revisions')->count(),
            'media' => DB::table('landing_page_media')->count(),
            'live_test_accounts_remaining' => DB::table('users')->where('email', 'like', 'stage10-%@example.test')->count(),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if (($argv[1] ?? '') === '--migrate') {
        $result = Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_14_120000_create_landing_page_content_tables.php', '--force' => true]);
    } else {
        $result = Artisan::call('migrate:status');
    }
    echo Artisan::output();
    exit($result);
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated landing database helper failed: '.$error::class.PHP_EOL);
    exit(1);
}
