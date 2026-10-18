<?php

declare(strict_types=1);

namespace App\Actions\Memberships;

use App\Models\MemberCredential;
use App\Models\Membership;
use Carbon\CarbonImmutable;

class IssueMemberCredential
{
    // Caller holds the membership row lock and owns the surrounding transaction.
    public function handle(Membership $membership, int $actorId, CarbonImmutable $now): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $credential = new MemberCredential;
        $credential->forceFill([
            'token_hash' => hash('sha256', $token, true),
            // The model encrypted cast performs encryption exactly once.
            'token_encrypted' => $token, 'issued_at' => $now, 'issued_by' => $actorId,
        ]);
        $membership->credentials()->save($credential);
    }
}
