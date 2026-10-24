<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\MemberProfile;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GetMemberProfile
{
    public function handle(int $actorId, int $sessionVersion): MemberProfile
    {
        return DB::transaction(function () use ($actorId, $sessionVersion): MemberProfile {
            Membership::query()->where('user_id', $actorId)->lockForUpdate()->first();
            $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($user === null || $user->status !== AccountStatus::Enabled) {
                throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
            }
            if ($user->auth_version !== $sessionVersion) {
                throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
            }
            $profile = $user->memberProfile()->first();
            if ($profile === null) {
                throw new ApiProblem('PROFILE_NOT_FOUND', 'Profil belum tersedia. Hubungi pengurus komunitas.', 404);
            }
            Gate::forUser($user)->authorize('view', $profile);

            return $profile;
        }, attempts: 3);
    }
}
