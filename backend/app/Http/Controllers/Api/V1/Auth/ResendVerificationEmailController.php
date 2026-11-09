<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResendVerificationEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

class ResendVerificationEmailController extends Controller
{
    public function __invoke(ResendVerificationRequest $request, ResendVerificationEmail $action): JsonResponse
    {
        $action->handle($request->validated('email'));

        return response()->json([
            'data' => ['result' => 'verification_requested', 'message' => 'Jika alamat email memenuhi syarat, email verifikasi akan diproses. Periksa kotak masuk dan spam.'],
            'meta' => ['request_id' => RequestId::get($request)],
        ], 202)->header('Cache-Control', 'no-store')->header('Retry-After', '60');
    }
}
