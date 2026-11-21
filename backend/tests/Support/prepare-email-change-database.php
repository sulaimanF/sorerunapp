<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$applicationEnv = is_file($root.'/.env') ? Dotenv\Dotenv::parse(file_get_contents($root.'/.env')) : [];
if (($applicationEnv['DB_DATABASE'] ?? '') === 'sorerunapp_test') {
    throw new RuntimeException('Application and test databases must be separate.');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'sorerunapp_test', 'DB_URL' => '', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false', 'LOG_CHANNEL' => 'stderr',
    'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/stage09-testing-config.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file($root.'/bootstrap/cache/stage09-testing-config.php')) {
    throw new RuntimeException('Unexpected cached test configuration.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('SELECT DATABASE() AS db_name, VERSION() AS version');
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'sorerunapp_test' || $identity->db_name !== 'sorerunapp_test'
    || ! str_starts_with((string) $identity->version, '8.4.') || config('mail.default') !== 'array') {
    throw new RuntimeException('Requires isolated sorerunapp_test on MySQL 8.4 with array mail.');
}
echo 'Verified: MySQL '.$identity->version.', sorerunapp_test, separate from application database, fake mail.'.PHP_EOL;
if (($argv[1] ?? '') === '--migrate') {
    $result = Artisan::call('migrate', ['--force' => true]);
    echo Artisan::output();
    exit($result);
}
$result = Artisan::call('migrate:status');
echo Artisan::output();
exit($result);
