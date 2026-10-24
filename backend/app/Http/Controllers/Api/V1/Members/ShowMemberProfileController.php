<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Actions\Members\GetMemberProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\Members\MemberProfileResource;
use App\Support\Auth\BrowserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowMemberProfileController extends Controller
{
    public function __invoke(Request $request, GetMemberProfile $action): JsonResponse
    {
        return (new MemberProfileResource($action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION))))->response()->header('Cache-Control', 'no-store');
    }
}
