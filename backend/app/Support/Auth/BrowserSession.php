<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class BrowserSession
{
    public const AUTH_VERSION = 'sorerunapp.auth_version';

    public static function end(Request $request): void
    {
        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        Auth::shouldUse('web');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
