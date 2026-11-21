<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Content;

use App\Actions\Content\DeleteLandingMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ListLandingMediaRequest;
use App\Http\Requests\Content\UploadLandingMediaRequest;
use App\Http\Resources\Content\LandingMediaResource;
use App\Models\LandingPageMedia;
use App\Services\Content\StoreLandingImage;
use App\Support\Auth\BrowserSession;
use App\Support\Http\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class LandingMediaController extends Controller
{
    public function index(ListLandingMediaRequest $request): JsonResponse
    {
        $rows = LandingPageMedia::query()->orderByDesc('id')->paginate(30);

        return response()->json([
            'data' => [
                'items' => $rows->getCollection()->map(fn ($row) => [
                    ...(new LandingMediaResource($row))->resolve($request), 'state' => $row->state,
                ])->all(),
                'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            ], 'meta' => ['request_id' => RequestId::get($request)],
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function upload(UploadLandingMediaRequest $request, StoreLandingImage $images): JsonResponse
    {
        $media = $images->handle((int) $request->user('web')->id, $request->session()->get(BrowserSession::AUTH_VERSION), $request->file('image'), RequestId::get($request));

        return (new LandingMediaResource($media))->additional(['meta' => ['request_id' => RequestId::get($request)]])
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function delete(Request $request, string $publicId, DeleteLandingMedia $delete): Response
    {
        $delete->handle((int) $request->user('web')->id, $request->session()->get(BrowserSession::AUTH_VERSION), $publicId, RequestId::get($request));

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function showPublic(string $publicId): Response
    {
        $media = LandingPageMedia::query()->where('public_id', $publicId)->where('state', 'ready')->firstOrFail();
        $visible = DB::table('landing_pages')->join('landing_page_revision_media', 'landing_pages.published_revision_id', '=', 'landing_page_revision_media.revision_id')
            ->where('landing_pages.id', 1)->where('landing_page_revision_media.media_id', $media->id)->exists();
        abort_unless($visible, 404);

        return $this->stream($media);
    }

    public function showAdmin(string $publicId): Response
    {
        return $this->stream(LandingPageMedia::query()->where('public_id', $publicId)->where('state', 'ready')->firstOrFail());
    }

    private function stream(LandingPageMedia $media): Response
    {
        $disk = Storage::disk('landing');
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->public_id.'.webp', [
            'Content-Type' => $media->mime, 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
        ], 'inline');
    }
}
