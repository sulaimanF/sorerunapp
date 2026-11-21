<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ProvisioningAccess
{
    /** Lock all existing users in ascending ID order, before provisioning/invitation rows. */
    public function lock(int $actorId, int $version, ?int $targetId = null): ?User
    {
        $users = User::query()->whereIn('id', array_unique(array_filter([$actorId, $targetId])))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $users->get($actorId);
        if ($actor === null || $actor->auth_version !== $version) {
            throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
        }
        Gate::forUser($actor)->authorize('provision', User::class);
        if ($targetId !== null && ! $users->has($targetId)) {
            throw new ApiProblem('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $targetId === null ? null : $users->get($targetId);
    }
}
