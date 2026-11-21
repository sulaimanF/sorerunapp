<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\AdminProvisioning;
use App\Services\Administration\AdminInvitationState;
use App\Services\Administration\ProvisioningAccess;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ResendAdminInvitation
{
    public function handle(int $actorId, int $version, int $targetId, string $requestId): array
    {
        return DB::transaction(function () use ($actorId, $version, $targetId, $requestId): array {
            $target = app(ProvisioningAccess::class)->lock($actorId, $version, $targetId);
            $record = AdminProvisioning::query()->where('user_id', $targetId)->lockForUpdate()->first();
            $state = app(AdminInvitationState::class);
            if ($record === null) {
                throw new ApiProblem('NOT_FOUND', 'Data undangan tidak ditemukan.', 404);
            }
            if ($record->activated_at !== null || ! $state->targetEligible($target)) {
                throw new ApiProblem('INVITATION_NOT_AVAILABLE', 'Akun tidak memenuhi syarat untuk undangan aktivasi.', 409);
            }
            $last = AdminInvitation::query()->where('user_id', $targetId)->latest('created_at')->orderByDesc('id')->lockForUpdate()->first();
            $seconds = $last === null ? 0 : (int) ceil(now('UTC')->diffInSeconds($last->created_at->copy()->addSeconds(AdminInvitationState::COOLDOWN_SECONDS), false));
            if ($seconds > 0) {
                throw new TooManyRequestsHttpException($seconds);
            }
            $state->issue($target, $actorId, $requestId);

            return $state->snapshot($target);
        }, attempts: 3);
    }
}
