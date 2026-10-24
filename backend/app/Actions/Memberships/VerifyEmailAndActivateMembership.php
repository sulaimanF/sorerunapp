<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VerifyEmailAndActivateMembership
{
    public function __construct(private readonly ActivatePendingMembership $activation) {}

    public function handle(int $actorId, string $id, string $emailHash, string $version, string $requestId, int $sessionVersion): array
    {
        if ((string) $actorId !== $id) {
            throw new ApiProblem('VERIFICATION_OWNER_MISMATCH', 'Tautan ini bukan milik akun yang sedang login.', 403);
        }

        return DB::transaction(function () use ($actorId, $emailHash, $version, $requestId, $sessionVersion): array {
            // Consistent order across activation, resend and card: membership -> user -> sequence -> credential.
            $membership = Membership::query()->where('user_id', $actorId)->lockForUpdate()->first();
            $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($user === null || $user->status !== AccountStatus::Enabled) {
                throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
            }
            if ($user->auth_version !== $sessionVersion) {
                throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
            }
            if (! hash_equals(sha1($user->email), $emailHash) || (string) $user->auth_version !== $version) {
                throw new ApiProblem('VERIFICATION_LINK_STALE', 'Tautan sudah tidak berlaku. Minta email verifikasi baru.', 403);
            }
            if ($membership === null || ! in_array($membership->status, [MembershipStatus::PendingVerification, MembershipStatus::Active], true)) {
                throw new ApiProblem('MEMBERSHIP_NOT_ACTIVATABLE', 'Status keanggotaan tidak dapat diaktifkan melalui verifikasi email.', 409);
            }
            if ($membership->status === MembershipStatus::Active) {
                if ($user->email_verified_at === null) {
                    throw new ApiProblem('MEMBERSHIP_STATE_CONFLICT', 'Data keanggotaan perlu diperiksa pengelola.', 409);
                }

                return $this->result('already_active', $membership);
            }
            $now = CarbonImmutable::now('UTC');
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => $now])->save();
                $this->audit($actorId, (int) $membership->id, 'member.email_verified', $requestId, $now);
            }

            return $this->activation->handleLocked($user, $membership, $requestId, $now);
        }, attempts: 3);
    }

    private function result(string $result, Membership $membership): array
    {
        return ['result' => $result, 'membership' => ['status' => $membership->status->value, 'member_number' => $membership->member_number]];
    }

    private function audit(int $actorId, int $membershipId, string $action, string $requestId, CarbonImmutable $now): void
    {
        (new AuditLog)->forceFill([
            'actor_user_id' => $actorId, 'action' => $action, 'target_type' => 'membership',
            'target_id' => $membershipId, 'occurred_at' => $now, 'request_id' => $requestId,
        ])->save();
    }
}
