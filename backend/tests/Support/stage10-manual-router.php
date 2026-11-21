<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

define('LARAVEL_START', microtime(true));
require __DIR__.'/stage10-environment.php';
try {
    $app = bootStage10(live: true, manual: true);
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__stage10_manual/health') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['environment' => app()->environment(), 'database' => DB::selectOne('SELECT DATABASE() AS db_name')->db_name,
            'mail' => config('mail.default'), 'gd_webp' => function_exists('imagewebp'), 'manual' => true], JSON_THROW_ON_ERROR);
    } else {
        $app->handleRequest(Request::capture());
    }
} catch (Throwable $error) {
    error_log('Stage10 manual bootstrap failed: '.$error::class);
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"Isolated Stage10 manual server unavailable"}';
}
