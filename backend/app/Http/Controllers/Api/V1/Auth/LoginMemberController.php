<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginMemberRequest;
use App\Http\Resources\Auth\BrowserSessionResource;
use App\Support\Auth\BrowserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LoginMemberController extends Controller
{
    public function __invoke(LoginMemberRequest $request, AuthenticateMember $action): JsonResponse
    {
        $user = $action->handle($request->toData());
        $request->session()->invalidate();
        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->put(BrowserSession::AUTH_VERSION, $user->auth_version);

        return (new BrowserSessionResource($user))->response()->header('Cache-Control', 'no-store');
    }
}
