<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResetMemberPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\Auth\PasswordResetResource;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request, ResetMemberPassword $action): JsonResponse
    {
        $action->handle($request->validated(), RequestId::get($request));

        return (new PasswordResetResource('password_reset'))->response()->header('Cache-Control', 'no-store');
    }
}
