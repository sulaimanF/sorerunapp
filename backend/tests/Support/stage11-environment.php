<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnforceCsrfInRegistrationTests;

function bootStage11(bool $live = false, bool $create = false): Application
{
    $root = dirname(__DIR__, 2);
    require_once $root.'/vendor/autoload.php';
    $application = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    if (($application['DB_DATABASE'] ?? '') === 'sorerunapp_stage11_test') {
        throw new RuntimeException('Application and Stage11 test databases must differ.');
    }
    foreach ([
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => str_repeat('t', 32),
        'APP_URL' => 'http://localhost:8130', 'FRONTEND_URL' => 'http://localhost:3130',
        'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/stage11-testing-config.php',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'sorerunapp_stage11_test', 'DB_URL' => '',
        'CACHE_STORE' => $live ? 'database' : 'array', 'CACHE_PREFIX' => 'stage11_live_',
        'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_TABLE' => 'cache', 'DB_CACHE_LOCK_CONNECTION' => 'mysql',
        'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql', 'SESSION_TABLE' => 'sessions',
        'SESSION_COOKIE' => 'stage11_live_session', 'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
        'SANCTUM_STATEFUL_DOMAINS' => 'localhost:3130,localhost:8130',
        'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_CONNECTION' => 'mysql',
        'MAIL_MAILER' => $live ? 'smtp' : 'array', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025',
        'MAIL_URL' => 'null', 'MAIL_USERNAME' => 'null', 'MAIL_PASSWORD' => 'null', 'MAIL_SCHEME' => 'null',
        'MAIL_FROM_ADDRESS' => 'stage11@example.test', 'MAIL_FROM_NAME' => 'Stage11 Synthetic Test',
        'OUTBOX_DISPATCH_ON_COMMIT' => $live ? 'true' : 'false', 'BCRYPT_ROUNDS' => '4', 'LOG_CHANNEL' => 'stderr',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    if (is_file($root.'/bootstrap/cache/stage11-testing-config.php')) {
        throw new RuntimeException('Unexpected cached test configuration.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || $db->getConfig('host') !== '127.0.0.1' || $db->getDatabaseName() !== 'sorerunapp_stage11_test') {
        throw new RuntimeException('Unsafe Stage11 target.');
    }
    if ($create) {
        $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $db->getConfig('username'), $db->getConfig('password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = $server->query('SELECT VERSION()')->fetchColumn();
        if (! str_starts_with($version, '8.4.')) {
            throw new RuntimeException('MySQL8.4 required.');
        }
        $server->exec('CREATE DATABASE IF NOT EXISTS sorerunapp_stage11_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $actual = $db->selectOne('SELECT DATABASE() AS db_name, VERSION() AS version');
    if (! app()->environment('testing') || $actual->db_name !== 'sorerunapp_stage11_test' || ! str_starts_with($actual->version, '8.4.')) {
        throw new RuntimeException('Unsafe effective Stage11 database.');
    }
    if ($live) {
        config(['sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class]);
    }
    config(['filesystems.disks.landing.root' => storage_path('framework/testing/stage11-media')]);
    $db->statement("SET SESSION time_zone = '+00:00'");

    return $app;
}
