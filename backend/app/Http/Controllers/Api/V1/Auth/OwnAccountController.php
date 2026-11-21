<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetOwnAccount;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\OwnAccountResource;
use App\Support\Auth\BrowserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnAccountController extends Controller
{
    public function __invoke(Request $request, GetOwnAccount $action): JsonResponse
    {
        if (count($request->query()) > 0) {
            throw new ApiProblem('BAD_REQUEST', 'Endpoint akun sendiri tidak menerima parameter.', 400);
        }
        $data = $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION));

        return (new OwnAccountResource($data))->response()->header('Cache-Control', 'no-store');
    }
}
