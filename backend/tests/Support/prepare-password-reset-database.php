<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test', 'MAIL_MAILER' => 'array', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'sorerunapp_test'
    || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
    throw new RuntimeException('Requires isolated sorerunapp_test on MySQL 8.4.');
}
$result = Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_09_120000_support_password_reset_delivery.php', '--force' => true]);
echo Artisan::output();
exit($result);
