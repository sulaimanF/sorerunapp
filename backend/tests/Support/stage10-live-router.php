<?php

declare(strict_types=1);

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
require __DIR__.'/stage10-environment.php';
try {
    $app = bootStage10(live: true);
    $app->handleRequest(Request::capture());
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"Isolated Stage10 test server unavailable"}';
}
