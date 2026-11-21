<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\ChangeAdministrativePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\AdministrativePasswordRequest;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\Response;

final class ChangeAdministrativePasswordController extends Controller
{
    public function __invoke(AdministrativePasswordRequest $request, ChangeAdministrativePassword $action, ?int $userId = null): Response
    {
        $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $request->validated(), RequestId::get($request), $userId);
        if ($userId === null) {
            BrowserSession::end($request);
        }

        return response()->noContent()->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
