<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::forUser($request->user('web'))->authorize('provision', User::class);

        return $next($request);
    }
}
