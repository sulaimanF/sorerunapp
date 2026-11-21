<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\ApiProblem;
use App\Models\EmailChangeRequest;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmailChangeState
{
    public const EXPIRY_MINUTES = 60;

    public const COOLDOWN_SECONDS = 60;

    // Call only in a transaction, following the global membership -> user lock order.
    public function lockOwner(int $actorId, int $sessionVersion): User
    {
        Membership::query()->where('user_id', $actorId)->lockForUpdate()->first();
        $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
        if ($user === null || $user->auth_version !== $sessionVersion) {
            throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
        }
        if ($user->status !== AccountStatus::Enabled) {
            throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
        }
        if ($user->email_verified_at === null) {
            throw new ApiProblem('EMAIL_NOT_VERIFIED', 'Verifikasi email Anda sebelum masuk.', 403);
        }

        return $user;
    }

    public function forUser(int $userId): ?array
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return null;
        }
        $pending = EmailChangeRequest::query()->where('user_id', $userId)->where('user_version', $user->auth_version)
            ->whereNull('revoked_at')->whereNull('consumed_at')->latest('created_at')->first();
        $last = EmailChangeRequest::query()->where('user_id', $userId)->latest('created_at')->first();
        $retryAfter = $last === null ? 0 : max(0, (int) ceil(now('UTC')->diffInSeconds($last->created_at->copy()->addSeconds(self::COOLDOWN_SECONDS), false)));

        return [
            'active_email' => $user->email,
            'retry_after' => $retryAfter,
            'pending' => $pending === null ? null : [
                'email' => $pending->new_email,
                'expires_at' => $pending->expires_at->utc()->toIso8601ZuluString(),
                'status' => $pending->expires_at->isPast() ? 'expired' : 'pending',
            ],
        ];
    }

    public function cancelDeliveries(int $userId): void
    {
        DB::table('notification_deliveries')->where('user_id', $userId)->where('type', 'email_change')
            ->whereIn('status', ['pending', 'processing', 'failed'])->update([
                'status' => 'cancelled', 'payload_encrypted' => null, 'lease_until' => null,
                'last_error_code' => 'EMAIL_CHANGE_NOT_CURRENT',
            ]);
    }
}
