<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\LandingPage;
use App\Models\LandingPageMedia;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class LandingContentAccess
{
    public function lockPage(int $actorId, int $sessionVersion): LandingPage
    {
        // Content never locks membership/event rows. Writers serialize user -> page -> media IDs.
        $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
        if ($actor === null || $actor->auth_version !== $sessionVersion) {
            throw new ApiProblem('SESSION_EXPIRED', 'Silakan masuk kembali.', 401);
        }
        Gate::forUser($actor)->authorize('manage', LandingPage::class);

        return LandingPage::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public function checkVersion(LandingPage $page, int $version): void
    {
        if ($page->draft_version !== $version) {
            throw new ApiProblem('CONTENT_VERSION_CONFLICT', 'Draft telah berubah. Muat ulang draft terbaru sebelum menyimpan atau menerbitkan.', 409);
        }
    }

    public function lockMedia(array $ids): array
    {
        $media = LandingPageMedia::query()->whereIn('public_id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($media->count() !== count($ids) || $media->contains(fn ($item): bool => $item->state !== 'ready')) {
            throw ValidationException::withMessages(['content' => ['Gambar tidak tersedia. Pilih kembali gambar yang sah.']]);
        }

        return $media->modelKeys();
    }

    public function audit(int $actorId, string $action, array $details, string $requestId): void
    {
        (new AuditLog)->forceFill([
            'actor_user_id' => $actorId, 'action' => $action,
            'target_type' => 'landing_page', 'target_id' => 1,
            'occurred_at' => now('UTC'), 'request_id' => $requestId,
            'after' => $details,
        ])->save();
    }
}
