<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Actions\Notifications\RecordNotificationDelivery;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\MemberNumberSequence;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;

class ActivatePendingMembership
{
    public function __construct(private readonly IssueMemberCredential $credentials, private readonly RecordNotificationDelivery $deliveries) {}

    // Caller owns the transaction and locks membership -> user. This action never verifies email.
    public function handleLocked(User $user, Membership $membership, string $requestId, CarbonImmutable $now): array
    {
        if ((int) $membership->user_id !== (int) $user->id || $membership->anonymized_at !== null
            || $membership->status !== MembershipStatus::PendingVerification) {
            throw new ApiProblem('MEMBERSHIP_NOT_ACTIVATABLE', 'Keanggotaan tidak dapat diaktifkan otomatis.', 409);
        }
        if ($user->status !== AccountStatus::Enabled) {
            throw new ApiProblem('ACCOUNT_BLOCKED', 'Akun tidak dapat digunakan.', 403);
        }
        if ($user->email_verified_at === null) {
            throw new ApiProblem('EMAIL_NOT_VERIFIED', 'Verifikasi email sebelum mengaktifkan keanggotaan.', 403);
        }
        if (! ($user->memberProfile?->isComplete() ?? false)) {
            return $this->result('email_verified_profile_incomplete', $membership);
        }
        $prefix = config('sorerunapp.membership.number_prefix');
        if (! is_string($prefix) || ! preg_match('/\A[A-Z][A-Z0-9]{1,11}\z/D', $prefix)) {
            throw new ApiProblem('MEMBER_NUMBER_CONFIGURATION_UNAVAILABLE', 'Konfigurasi nomor anggota belum tersedia.', 503);
        }
        if ($membership->member_number === null) {
            $sequence = MemberNumberSequence::query()->whereKey('membership')->lockForUpdate()->first();
            if ($sequence === null || $sequence->next_value < 1 || $sequence->next_value >= PHP_INT_MAX) {
                throw new ApiProblem('MEMBER_NUMBER_UNAVAILABLE', 'Nomor anggota belum dapat diterbitkan.', 503);
            }
            $membership->member_number = $prefix.'-'.str_pad((string) $sequence->next_value, 6, '0', STR_PAD_LEFT);
            $sequence->forceFill(['next_value' => $sequence->next_value + 1])->save();
        }
        $membership->forceFill(['status' => MembershipStatus::Active, 'joined_at' => $membership->joined_at ?? $now])->save();
        $this->credentials->handle($membership, (int) $user->id, $now);
        (new AuditLog)->forceFill([
            'actor_user_id' => $user->id, 'action' => 'membership.activated', 'target_type' => 'membership',
            'target_id' => $membership->id, 'occurred_at' => $now, 'request_id' => $requestId,
        ])->save();
        $this->deliveries->handle($user, 'membership_activated', 'membership_activated:'.$membership->id, $now);

        return $this->result('membership_activated', $membership);
    }

    private function result(string $result, Membership $membership): array
    {
        return ['result' => $result, 'membership' => ['status' => $membership->status->value, 'member_number' => $membership->member_number]];
    }
}
