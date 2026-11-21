<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Services\Administration\AdminInvitationState;
use App\Services\Auth\EmailChangeState;
use Illuminate\Support\Facades\DB;

final class GetOwnAccount
{
    public function handle(int $actorId, int $version): array
    {
        return DB::transaction(function () use ($actorId, $version): array {
            $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($user === null || $user->auth_version !== $version) {
                throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
            }
            if ($user->status !== AccountStatus::Enabled) {
                throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
            }
            if (AdminInvitationState::pending($user)) {
                throw new ApiProblem('ADMIN_ACTIVATION_REQUIRED', 'Aktifkan akun melalui undangan Admin.', 403);
            }
            if ($user->email_verified_at === null) {
                throw new ApiProblem('EMAIL_NOT_VERIFIED', 'Verifikasi email Anda sebelum masuk.', 403);
            }

            return [
                'full_name' => $user->name, 'email' => $user->email, 'email_verified' => true,
                'email_change' => app(EmailChangeState::class)->forUser($actorId),
            ];
        }, attempts: 3);
    }
}
