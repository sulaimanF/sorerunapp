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
        // The signature middleware authenticates the URL, not a browser account.
        if ($request->isMethod('GET')) {
            return response()->json(['data' => ['result' => 'verification_link_ready'], 'meta' => ['request_id' => RequestId::get($request)]])
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        // Retire a legacy unverified session before proof makes its subject eligible.
        $existing = Auth::guard('web')->user();
        if ($existing !== null && $existing->email_verified_at === null) {
            BrowserSession::end($request);
        }
        $version = (string) $request->query('v');
        $result = $action->handle((int) $id, $id, $hash, $version, RequestId::get($request), (int) $version, publicProof: true);

        return (new EmailVerificationResultResource($result))->response()->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
