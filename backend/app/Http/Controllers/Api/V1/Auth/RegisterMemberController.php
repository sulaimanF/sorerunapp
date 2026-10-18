<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterMemberRequest;
use App\Http\Resources\Auth\RegistrationReceiptResource;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;

class RegisterMemberController extends Controller
{
    public function __invoke(RegisterMemberRequest $request, RegisterMember $action): JsonResponse
    {
        $action->handle($request->toData(), RequestId::get($request));

        return (new RegistrationReceiptResource([]))->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store');
    }
}
