<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RequestPasswordReset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Resources\Auth\PasswordResetResource;
use Illuminate\Http\JsonResponse;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request, RequestPasswordReset $action): JsonResponse
    {
        $action->handle($request->validated('email'));

        return (new PasswordResetResource('password_reset_requested'))->response()->setStatusCode(202)
            ->header('Cache-Control', 'no-store')->header('Retry-After', (string) config('auth.passwords.users.throttle'));
    }
}
