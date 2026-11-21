<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\AcceptFirstAccountActivation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\AcceptAdminInvitationRequest;
use App\Http\Resources\Administration\AdminActivationResource;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

final class AcceptFirstAccountActivationController extends Controller
{
    public function __invoke(AcceptAdminInvitationRequest $request, AcceptFirstAccountActivation $action): JsonResponse
    {
        $action->handle($request->validated(), RequestId::get($request));

        return (new AdminActivationResource(['result' => 'first_account_activated']))->response()
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
