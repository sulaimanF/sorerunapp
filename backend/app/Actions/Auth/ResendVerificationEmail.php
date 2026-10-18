<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Notifications\RecordNotificationDelivery;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ResendVerificationEmail
{
    public function handle(int $actorId, int $sessionVersion): void
    {
        DB::transaction(function () use ($actorId, $sessionVersion): void {
            $membership = Membership::query()->where('user_id', $actorId)->lockForUpdate()->first();
            $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($user === null || $user->status !== AccountStatus::Enabled) {
                throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
            }
            if ($user->auth_version !== $sessionVersion) {
                throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
            }
            if ($membership === null || ! in_array($membership->status, [MembershipStatus::PendingVerification, MembershipStatus::Active], true)) {
                throw new ApiProblem('MEMBERSHIP_NOT_ACTIVATABLE', 'Status keanggotaan tidak dapat diaktifkan melalui verifikasi email.', 409);
            }
            if ($user->email_verified_at !== null) {
                return;
            }
            $now = CarbonImmutable::now('UTC');
            $last = NotificationDelivery::query()->where('user_id', $actorId)->where('type', 'email_verification')->latest('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($last !== null && $last->created_at->greaterThan($now->subSeconds(60))) {
                $remaining = max(1, (int) ceil($last->created_at->timestamp + 60 - $now->timestamp));
                throw new TooManyRequestsHttpException($remaining, 'Tunggu sebelum meminta email verifikasi kembali.');
            }
            app(RecordNotificationDelivery::class)->handle($user, 'email_verification', 'verification_resend:'.$actorId.':'.Str::uuid(), $now);
        }, attempts: 3);
    }
}
