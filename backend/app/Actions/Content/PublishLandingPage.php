<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Models\LandingPageRevision;
use App\Services\Content\LandingContentAccess;
use App\Services\Content\LandingDocument;
use Illuminate\Support\Facades\DB;

final class PublishLandingPage
{
    public function __construct(private LandingDocument $documents, private LandingContentAccess $access) {}

    public function handle(int $actorId, int $sessionVersion, int $version, string $requestId): LandingPageRevision
    {
        return DB::transaction(function () use ($actorId, $sessionVersion, $version, $requestId): LandingPageRevision {
            $page = $this->access->lockPage($actorId, $sessionVersion);
            $this->access->checkVersion($page, $version);
            // Current read: a repeatable-read snapshot may predate waiting for the page lock.
            $existing = LandingPageRevision::query()->where('landing_page_id', 1)->where('draft_version', $version)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $snapshot = $this->documents->snapshot($page->draft ?? $this->documents->empty());
            $mediaIds = $this->access->lockMedia($this->documents->mediaIds($snapshot));
            $revision = (new LandingPageRevision)->forceFill([
                'landing_page_id' => 1, 'draft_version' => $version,
                'content' => $snapshot, 'published_by' => $actorId, 'published_at' => now('UTC'),
            ]);
            $revision->save();
            foreach ($mediaIds as $id) {
                DB::table('landing_page_revision_media')->insert(['revision_id' => $revision->id, 'media_id' => $id]);
            }
            $page->forceFill(['published_revision_id' => $revision->id])->save();
            $this->access->audit($actorId, 'content.published', ['revision_id' => $revision->id, 'draft_version' => $version], $requestId);

            return $revision;
        }, attempts: 3);
    }
}
