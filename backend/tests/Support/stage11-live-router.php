<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/stage11-environment.php';
try {
    $app = bootStage11(live: true);
    Route::get('/__stage11/health', fn () => response()->json([
        'environment' => app()->environment(), 'database' => DB::selectOne('SELECT DATABASE() AS n')->n,
        'mail' => config('mail.default'), 'queue' => config('queue.default'),
    ]));
    $app->handleRequest(Request::capture());
} catch (Throwable $exception) {
    error_log('Stage11 live router: '.get_class($exception));
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"Isolated test API unavailable"}';
}
