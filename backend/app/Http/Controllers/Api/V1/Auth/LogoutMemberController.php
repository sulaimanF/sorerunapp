<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\BrowserSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogoutMemberController extends Controller
{
    public function __invoke(Request $request): Response
    {
        BrowserSession::end($request);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
