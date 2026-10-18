<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ValidateLoginJson;
use App\Http\Middleware\ValidateRegistrationJson;
use App\Support\Http\ApiExceptionRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/email-verification.php'));
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('outbox:dispatch --limit=100')->everyMinute()->withoutOverlapping(5);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prependToGroup('api', [AssignRequestId::class, ValidateRegistrationJson::class, ValidateLoginJson::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            return app(ApiExceptionRenderer::class)->render($exception, $request);
        });

        // Database bindings can contain account/profile data; log only technical codes.
        $exceptions->report(function (QueryException $exception): bool {
            Log::error('Database operation failed.', [
                'sql_state' => (string) ($exception->errorInfo[0] ?? ''),
                'driver_code' => (int) ($exception->errorInfo[1] ?? 0),
            ]);

            return false;
        });
    })->create();
