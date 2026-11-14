<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmEmailChangeRequest;
use App\Http\Resources\Auth\EmailChangeResource;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

class ConfirmEmailChangeController extends Controller
{
    public function __invoke(ConfirmEmailChangeRequest $request, ConfirmEmailChange $action): JsonResponse
    {
        $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $request->validated(), RequestId::get($request));
        BrowserSession::end($request);

        return (new EmailChangeResource('email_changed'))->response()->header('Cache-Control', 'no-store');
    }
}
