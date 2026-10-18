<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireVerificationSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Email navigation has no first-party Origin. The web group supplies the session once.
        if (! $request->hasSession() || ! Auth::guard('web')->check()) {
            throw new ApiProblem('UNAUTHENTICATED', 'Login dengan akun pemilik tautan, lalu buka kembali tautan verifikasi.', 401);
        }

        return $next($request);
    }
}
