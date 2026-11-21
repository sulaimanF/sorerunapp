<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnforceCsrfInRegistrationTests;

function bootStage10(bool $live = false, bool $manual = false): Application
{
    $root = dirname(__DIR__, 2);
    require_once $root.'/vendor/autoload.php';
    $application = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    if (($application['DB_DATABASE'] ?? '') === 'sorerunapp_test') {
        throw new RuntimeException('Application and test databases must differ.');
    }
    foreach ([
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'APP_URL' => $manual ? 'http://127.0.0.1:8120' : 'http://localhost:8110',
        'FRONTEND_URL' => $manual ? 'http://127.0.0.1:3120' : 'http://localhost:3111',
        'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/'.($manual ? 'stage10-manual-config.php' : 'stage10-testing-config.php'),
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'sorerunapp_test', 'DB_URL' => '',
        'CACHE_STORE' => $live ? 'database' : 'array', 'CACHE_PREFIX' => $manual ? 'stage10_manual_' : 'stage10_live_',
        'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_TABLE' => 'cache', 'DB_CACHE_LOCK_CONNECTION' => 'mysql',
        'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql',
        'SESSION_COOKIE' => $manual ? 'stage10_manual_session' : 'stage10_live_session',
        'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
        'SANCTUM_STATEFUL_DOMAINS' => $manual ? '127.0.0.1:3120,127.0.0.1:8120' : 'localhost:3111,localhost:8110',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false',
        'LOG_CHANNEL' => 'stderr', 'BCRYPT_ROUNDS' => '4',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    if (is_file($root.'/bootstrap/cache/'.($manual ? 'stage10-manual-config.php' : 'stage10-testing-config.php'))) {
        throw new RuntimeException('Unexpected cached test configuration.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $actual = DB::selectOne('SELECT DATABASE() AS db_name, VERSION() AS version');
    if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || $actual->db_name !== 'sorerunapp_test' || ! str_starts_with($actual->version, '8.4.')
        || config('mail.default') !== 'array' || config('database.connections.mysql.host') !== '127.0.0.1') {
        throw new RuntimeException('Unsafe Stage10 test target.');
    }
    config(['filesystems.disks.landing.root' => storage_path('framework/testing/'.($manual ? 'stage10-manual-media' : 'stage10-media'))]);
    if ($live) {
        config(['sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class]);
    }
    DB::statement("SET SESSION time_zone = '+00:00'");

    return $app;
}
