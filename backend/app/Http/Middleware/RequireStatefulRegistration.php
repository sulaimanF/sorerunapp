<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

class RequireStatefulRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        // Public registration still requires Sanctum's browser session + CSRF stack.
        if (! EnsureFrontendRequestsAreStateful::fromFrontend($request) || ! $request->hasSession()) {
            throw new ApiProblem('CSRF_MISMATCH', 'Sesi permintaan tidak valid. Muat ulang halaman lalu coba kembali.', 419);
        }

        return $next($request);
    }
}
