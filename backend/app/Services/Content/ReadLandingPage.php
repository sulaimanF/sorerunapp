<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Http\Resources\Content\LandingMediaResource;
use App\Models\LandingPage;
use App\Models\LandingPageMedia;
use App\Models\LandingPageRevision;
use Illuminate\Http\Request;

final class ReadLandingPage
{
    public function __construct(private LandingDocument $documents) {}

    public function published(Request $request): array
    {
        // One statement selects the pointer and immutable snapshot; no draft is selected.
        $revision = LandingPageRevision::query()
            ->join('landing_pages', 'landing_pages.published_revision_id', '=', 'landing_page_revisions.id')
            ->where('landing_pages.id', 1)->select('landing_page_revisions.*')->first();
        if ($revision === null) {
            return ['publication' => null];
        }

        return ['publication' => [
            'version' => $revision->draft_version, 'published_at' => $revision->published_at->utc()->toIso8601ZuluString(),
            'content' => $revision->content,
            'media' => $this->media($request, $this->documents->mediaIds($revision->content)),
        ]];
    }

    public function draft(Request $request): array
    {
        $page = LandingPage::query()->findOrFail(1);
        $revision = $page->published_revision_id === null ? null : LandingPageRevision::query()->findOrFail($page->published_revision_id);
        $content = $page->draft ?? $this->documents->empty();

        return [
            'version' => $page->draft_version, 'content' => $content,
            'published_version' => $revision?->draft_version,
            'published_at' => $revision?->published_at->utc()->toIso8601ZuluString(),
            'media' => $this->media($request, $this->documents->mediaIds($content)),
        ];
    }

    public function media(Request $request, array $ids): array
    {
        return LandingPageMedia::query()->where('state', 'ready')->whereIn('public_id', $ids)->orderBy('id')->get()
            ->map(fn (LandingPageMedia $media): array => (new LandingMediaResource($media))->resolve($request))->all();
    }
}
