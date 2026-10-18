<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\Auth\BrowserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentBrowserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionUser = Auth::guard('web')->user();
        if (! $request->hasSession() || $sessionUser === null) {
            throw new ApiProblem('UNAUTHENTICATED', 'Silakan masuk untuk melanjutkan.', 401);
        }

        // Re-read instead of trusting the guard's previously loaded model.
        $user = User::query()->find($sessionUser->getAuthIdentifier());
        if ($user === null) {
            BrowserSession::end($request);
            throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
        }

        if ($user->status !== AccountStatus::Enabled) {
            BrowserSession::end($request);
            throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan. Hubungi pengurus komunitas.', 403);
        }

        $version = $request->session()->get(BrowserSession::AUTH_VERSION);
        if (! is_int($version) || $version !== $user->auth_version) {
            BrowserSession::end($request);
            throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
        }

        Auth::guard('web')->setUser($user);
        Auth::guard('sanctum')->setUser($user);

        return $next($request);
    }
}
