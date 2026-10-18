<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\EmailVerificationResultResource;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyMemberEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash, VerifyEmailAndActivateMembership $action): JsonResponse
    {
        $version = $request->query('v');
        $result = $action->handle((int) Auth::guard('web')->id(), $id, $hash, is_string($version) ? $version : '', RequestId::get($request), (int) $request->session()->get(BrowserSession::AUTH_VERSION));

        return (new EmailVerificationResultResource($result))->response()->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
