<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Actions\Content\PublishLandingPage;
use App\Actions\Content\SaveLandingDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\LandingVersionRequest;
use App\Http\Requests\Content\SaveLandingDraftRequest;
use App\Http\Resources\Content\LandingDraftResource;
use App\Services\Content\LandingDocument;
use App\Services\Content\ReadLandingPage;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminLandingPageController extends Controller
{
    public function show(Request $request, ReadLandingPage $pages): JsonResponse
    {
        return $this->draftResponse($request, $pages->draft($request));
    }

    public function preview(Request $request, ReadLandingPage $pages, LandingDocument $documents): JsonResponse
    {
        $draft = $pages->draft($request);
        $draft['content'] = $documents->snapshot($draft['content']);

        return $this->draftResponse($request, $draft);
    }

    public function save(SaveLandingDraftRequest $request, SaveLandingDraft $save): JsonResponse
    {
        $version = $save->handle((int) $request->user('web')->id, $request->session()->get(BrowserSession::AUTH_VERSION), (int) $request->validated('version'), $request->validated('content'), RequestId::get($request));

        return $this->respond($request, ['version' => $version]);
    }

    public function publish(LandingVersionRequest $request, PublishLandingPage $publish): JsonResponse
    {
        $revision = $publish->handle((int) $request->user('web')->id, $request->session()->get(BrowserSession::AUTH_VERSION), (int) $request->validated('version'), RequestId::get($request));

        return $this->respond($request, ['version' => $revision->draft_version, 'published_at' => $revision->published_at->utc()->toIso8601ZuluString()]);
    }

    private function respond(Request $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => RequestId::get($request)]])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function draftResponse(Request $request, array $data): JsonResponse
    {
        return (new LandingDraftResource($data))->additional(['meta' => ['request_id' => RequestId::get($request)]])
            ->response()->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
