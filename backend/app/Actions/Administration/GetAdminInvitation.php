<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Exceptions\ApiProblem;
use App\Services\Administration\AdminInvitationState;
use App\Services\Administration\ProvisioningAccess;
use Illuminate\Support\Facades\DB;

final class GetAdminInvitation
{
    public function handle(int $actorId, int $version, int $targetId): array
    {
        return DB::transaction(function () use ($actorId, $version, $targetId): array {
            $target = app(ProvisioningAccess::class)->lock($actorId, $version, $targetId);
            if (! $target->roles()->where('code', 'admin')->exists()) {
                throw new ApiProblem('NOT_FOUND', 'Data undangan tidak ditemukan.', 404);
            }

            return app(AdminInvitationState::class)->snapshot($target);
        }, attempts: 3);
    }
}
