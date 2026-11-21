<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\InvitationPurpose;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Administration\AdminInvitationState;
use App\Services\Administration\FirstAccountActivation;
use Illuminate\Support\Facades\DB;

final class IssueFirstAccountActivation
{
    public function handle(int $userId, string $email, string $requestId): int
    {
        return DB::transaction(function () use ($userId, $email, $requestId): int {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            $fence = DB::table('security_bootstraps')->where('name', 'first_super_admin')->lockForUpdate()->first();
            $state = app(FirstAccountActivation::class);
            if ($user === null || ! hash_equals($user->email, $email) || ! $state->eligible($user) || ! $state->fenceAvailable($fence, $userId)) {
                throw new ApiProblem('FIRST_ACTIVATION_NOT_AVAILABLE', 'Target harus ID/email akun enabled, belum terverifikasi, tanpa role/profil/membership dan sesuai bootstrap pertama yang belum selesai.', 409);
            }
            $latest = AdminInvitation::query()->where('user_id', $userId)->where('purpose', InvitationPurpose::FirstAccount->value)
                ->latest('created_at')->orderByDesc('id')->lockForUpdate()->first();
            $retry = $latest === null ? 0 : max(0, (int) ceil(now('UTC')->diffInSeconds($latest->created_at->addSeconds(AdminInvitationState::COOLDOWN_SECONDS), false)));
            if ($retry > 0) {
                throw new ApiProblem('FIRST_ACTIVATION_COOLDOWN', 'Tunggu '.$retry.' detik sebelum menerbitkan ulang undangan aktivasi awal.', 429);
            }
            if (AdminInvitation::query()->where('user_id', $userId)->where('purpose', InvitationPurpose::FirstAccount->value)
                ->where('created_at', '>', now('UTC')->subHour())->count() >= 5) {
                throw new ApiProblem('FIRST_ACTIVATION_RATE_LIMIT', 'Batas lima undangan aktivasi awal per jam telah tercapai.', 429);
            }
            DB::table('security_bootstraps')->where('name', 'first_super_admin')->update(['user_id' => $userId]);
            app(AdminInvitationState::class)->issue($user, null, $requestId, InvitationPurpose::FirstAccount);

            return (int) NotificationDelivery::query()->where('user_id', $userId)->where('type', InvitationPurpose::FirstAccount->value)->latest('id')->value('id');
        }, attempts: 3);
    }
}
