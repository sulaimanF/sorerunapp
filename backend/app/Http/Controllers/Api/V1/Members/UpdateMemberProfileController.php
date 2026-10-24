<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Members;

use App\Actions\Members\UpdateMemberProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\UpdateMemberProfileRequest;
use App\Http\Resources\Members\MemberProfileResource;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

class UpdateMemberProfileController extends Controller
{
    public function __invoke(UpdateMemberProfileRequest $request, UpdateMemberProfile $action): JsonResponse
    {
        $profile = $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $request->toData(), RequestId::get($request));

        return (new MemberProfileResource($profile))->response()->header('Cache-Control', 'no-store');
    }
}
