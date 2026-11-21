<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestEmailChangeRequest;
use App\Http\Resources\Auth\EmailChangeResource;
use App\Services\Auth\EmailChangeState;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

class RequestEmailChangeController extends Controller
{
    public function __invoke(RequestEmailChangeRequest $request, RequestEmailChange $action): JsonResponse
    {
        $data = $request->validated();
        $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $data['email'], $data['password'], RequestId::get($request));

        return (new EmailChangeResource('email_change_requested'))->response()->setStatusCode(202)
            ->header('Cache-Control', 'no-store')->header('Retry-After', (string) EmailChangeState::COOLDOWN_SECONDS);
    }
}
