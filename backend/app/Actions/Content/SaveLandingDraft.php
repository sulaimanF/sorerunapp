<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Services\Content\LandingContentAccess;
use App\Services\Content\LandingDocument;
use Illuminate\Support\Facades\DB;

final class SaveLandingDraft
{
    public function __construct(private LandingDocument $documents, private LandingContentAccess $access) {}

    public function handle(int $actorId, int $sessionVersion, int $version, array $content, string $requestId): int
    {
        $content = $this->documents->validate($content);

        return DB::transaction(function () use ($actorId, $sessionVersion, $version, $content, $requestId): int {
            $page = $this->access->lockPage($actorId, $sessionVersion);
            $this->access->checkVersion($page, $version);
            $this->access->lockMedia($this->documents->mediaIds($content));
            $page->forceFill(['draft' => $content, 'draft_version' => $version + 1, 'updated_by' => $actorId])->save();
            $this->access->audit($actorId, 'content.draft_saved', ['draft_version' => $page->draft_version], $requestId);

            return $page->draft_version;
        }, attempts: 3);
    }
}
