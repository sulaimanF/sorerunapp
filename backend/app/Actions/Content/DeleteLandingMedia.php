<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Exceptions\ApiProblem;
use App\Models\LandingPageMedia;
use App\Services\Content\LandingContentAccess;
use App\Services\Content\LandingDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DeleteLandingMedia
{
    public function __construct(private LandingContentAccess $access, private LandingDocument $documents) {}

    public function handle(int $actorId, int $sessionVersion, string $publicId, string $requestId): void
    {
        $media = DB::transaction(function () use ($actorId, $sessionVersion, $publicId, $requestId): LandingPageMedia {
            $page = $this->access->lockPage($actorId, $sessionVersion);
            $media = LandingPageMedia::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if (in_array($media->public_id, $this->documents->mediaIds($page->draft ?? $this->documents->empty()), true)
                || DB::table('landing_page_revision_media')->where('media_id', $media->id)->lockForUpdate()->first() !== null) {
                throw new ApiProblem('MEDIA_IN_USE', 'Gambar masih digunakan draft atau revisi yang dipertahankan.', 409);
            }
            if ($media->state !== 'deleting') {
                $media->forceFill(['state' => 'deleting'])->save();
                $this->access->audit($actorId, 'content.media_deletion_requested', ['media_id' => $media->id], $requestId);
            }

            return $media;
        }, attempts: 3);
        try {
            if (! Storage::disk('landing')->delete($media->path)) {
                throw new ApiProblem('MEDIA_DELETE_PENDING', 'Penghapusan gambar belum selesai. Coba kembali.', 503);
            }
        } catch (Throwable) {
            // Tombstone is durable and excluded from new drafts; repeating DELETE resumes cleanup.
            throw new ApiProblem('MEDIA_DELETE_PENDING', 'Penghapusan gambar belum selesai. Coba kembali.', 503);
        }
        LandingPageMedia::query()->whereKey($media->id)->where('state', 'deleting')->delete();
    }
}
