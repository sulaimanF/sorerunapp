<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\CreateAdminAccount;
use App\Actions\Administration\DeleteAdminAccount;
use App\Actions\Administration\GetAdminInvitation;
use App\Actions\Administration\ListAdminAccounts;
use App\Actions\Administration\ResendAdminInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CreateAdminAccountRequest;
use App\Http\Requests\Administration\DeleteAdminAccountRequest;
use App\Http\Requests\Administration\ListAdminAccountsRequest;
use App\Http\Requests\Administration\ResendAdminInvitationRequest;
use App\Http\Resources\Administration\AdminAccountListResource;
use App\Http\Resources\Administration\AdminAccountResource;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminAccountController extends Controller
{
    public function destroy(DeleteAdminAccountRequest $request, int $userId, DeleteAdminAccount $action): Response
    {
        $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $userId, RequestId::get($request));

        return response()->noContent()->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(ListAdminAccountsRequest $request, ListAdminAccounts $action): JsonResponse
    {
        $accounts = $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), (int) ($request->validated()['page'] ?? 1));

        return (new AdminAccountListResource($accounts))->response()
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function store(CreateAdminAccountRequest $request, CreateAdminAccount $action): JsonResponse
    {
        $data = $action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $request->validated(), RequestId::get($request));

        return $this->response($data, 201);
    }

    public function show(Request $request, int $userId, GetAdminInvitation $action): JsonResponse
    {
        return $this->response($action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $userId));
    }

    public function resend(ResendAdminInvitationRequest $request, int $userId, ResendAdminInvitation $action): JsonResponse
    {
        return $this->response($action->handle((int) $request->user('web')->id, (int) $request->session()->get(BrowserSession::AUTH_VERSION), $userId, RequestId::get($request)), 202);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return (new AdminAccountResource($data))->response()->setStatusCode($status)
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Retry-After', (string) $data['retry_after']);
    }
}
