<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Memberships;

use App\Actions\Memberships\GetMembershipCard;
use App\Http\Controllers\Controller;
use App\Http\Resources\Memberships\MembershipCardResource;
use App\Support\Auth\BrowserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipCardController extends Controller
{
    public function __invoke(Request $request, GetMembershipCard $action): JsonResponse
    {
        return (new MembershipCardResource($action->handle((int) $request->user()->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION))))->response()->header('Cache-Control', 'no-store');
    }
}
