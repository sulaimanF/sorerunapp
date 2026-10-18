<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiProblem;
use App\Models\MemberCredential;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

class GetMembershipCard
{
    public function handle(int $actorId, int $sessionVersion): array
    {
        return DB::transaction(function () use ($actorId, $sessionVersion): array {
            $membership = Membership::query()->where('user_id', $actorId)->lockForUpdate()->first();
            $user = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($user === null || $user->status !== AccountStatus::Enabled || $user->email_verified_at === null
                || $membership?->status !== MembershipStatus::Active || ! ($user->memberProfile?->isComplete() ?? false)) {
                throw new ApiProblem('MEMBERSHIP_CARD_UNAVAILABLE', 'Kartu tersedia setelah email terverifikasi dan keanggotaan aktif.', 403);
            }
            if ($user->auth_version !== $sessionVersion) {
                throw new ApiProblem('SESSION_EXPIRED', 'Sesi sudah berakhir. Silakan masuk kembali.', 401);
            }
            $credential = MemberCredential::query()->where('membership_id', $membership->id)->whereNull('revoked_at')->lockForUpdate()->first();
            try {
                $token = $credential?->token_encrypted;
            } catch (DecryptException) {
                throw new ApiProblem('CREDENTIAL_UNAVAILABLE', 'Kartu belum dapat ditampilkan. Hubungi pengelola.', 503);
            }
            if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token)
                || ! hash_equals((string) $credential->token_hash, hash('sha256', $token, true))) {
                throw new ApiProblem('CREDENTIAL_UNAVAILABLE', 'Kartu belum dapat ditampilkan. Hubungi pengelola.', 503);
            }

            return [
                'community_name' => (string) config('sorerunapp.community_name'),
                'full_name' => $user->memberProfile->full_name, 'member_number' => $membership->member_number,
                'qr_payload' => 'src-member:v1:'.$token, 'issued_at' => $credential->issued_at->toIso8601String(),
            ];
        }, attempts: 3);
    }
}
