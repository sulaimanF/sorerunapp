<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LandingPage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class RequireLandingPageAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::forUser($request->user('web'))->authorize('manage', LandingPage::class);

        return $next($request);
    }
}
