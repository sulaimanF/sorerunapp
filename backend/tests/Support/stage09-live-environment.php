<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

function bootStage09Live(): Application
{
    $root = dirname(__DIR__, 2);
    require_once $root.'/vendor/autoload.php';
    $applicationEnv = is_file($root.'/.env') ? Dotenv\Dotenv::parse(file_get_contents($root.'/.env')) : [];
    if (($applicationEnv['DB_DATABASE'] ?? '') === 'sorerunapp_test') {
        throw new RuntimeException('Application and test databases must be separate.');
    }
    foreach ([
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
        'APP_URL' => 'http://localhost:8109', 'FRONTEND_URL' => 'http://localhost:3119',
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test', 'DB_URL' => '',
        'DB_QUEUE_CONNECTION' => 'mysql', 'DB_QUEUE_TABLE' => 'jobs',
        'DB_CACHE_CONNECTION' => 'mysql', 'DB_CACHE_TABLE' => 'cache', 'DB_CACHE_LOCK_CONNECTION' => 'mysql', 'DB_CACHE_LOCK_TABLE' => 'cache_locks',
        'CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'stage09_live_',
        'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'mysql', 'SESSION_COOKIE' => 'stage09_live_session',
        'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
        'SANCTUM_STATEFUL_DOMAINS' => 'localhost:3119,localhost:8109',
        'QUEUE_CONNECTION' => 'database', 'OUTBOX_DISPATCH_ON_COMMIT' => 'true',
        'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1029', 'MAIL_SCHEME' => 'smtp',
        'MAIL_URL' => 'null', 'MAIL_USERNAME' => 'null', 'MAIL_PASSWORD' => 'null', 'MAIL_FROM_ADDRESS' => 'no-reply@example.test',
        'LOG_CHANNEL' => 'stderr', 'BCRYPT_ROUNDS' => '4',
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
        || $identity->db_name !== 'sorerunapp_test' || ! str_starts_with((string) $identity->version, '8.4.')
        || config('queue.connections.database.connection') !== 'mysql' || config('session.connection') !== 'mysql'
        || config('cache.stores.database.connection') !== 'mysql'
        || config('mail.mailers.smtp.host') !== '127.0.0.1' || (int) config('mail.mailers.smtp.port') !== 1029) {
        throw new RuntimeException('Unsafe Stage09 live test environment.');
    }

    return $app;
}
