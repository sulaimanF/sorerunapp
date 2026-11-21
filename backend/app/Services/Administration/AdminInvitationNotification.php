<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\InvitationPurpose;
use App\Exceptions\ApiProblem;
use App\Models\AdminInvitation;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ActivateAdminAccount;

final class AdminInvitationNotification
{
    public function forDelivery(NotificationDelivery $delivery, User $user): ?ActivateAdminAccount
    {
        $proof = $delivery->payload_encrypted;
        if (! is_array($proof) || ! is_string($proof['invitation_id'] ?? null) || ! is_string($proof['token'] ?? null)) {
            return null;
        }
        $invitation = AdminInvitation::query()->whereKey($proof['invitation_id'])->where('user_id', $user->id)->first();
        $state = app(AdminInvitationState::class);
        $first = $delivery->type === InvitationPurpose::FirstAccount->value;
        if ($invitation === null || $invitation->purpose->value !== $delivery->type
            || ($first ? ! app(FirstAccountActivation::class)->current($invitation, $user)
                : (! AdminInvitationState::pending($user) || ! $state->targetEligible($user)))
            || $invitation->revoked_at !== null || $invitation->consumed_at !== null
            || $invitation->expires_at->lessThanOrEqualTo(now('UTC')) || $invitation->user_version !== $user->auth_version
            || ! hash_equals($invitation->email, $user->email)
            || ! hash_equals($invitation->token_hash, hash('sha256', $proof['token']))) {
            return null;
        }
        $root = (string) config('sorerunapp.frontend_url');
        $parts = parse_url($root);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new ApiProblem('EMAIL_CONFIGURATION_UNAVAILABLE', 'Konfigurasi email belum tersedia.', 503);
        }
        $url = rtrim($root, '/').($first ? '/activate-first-account#' : '/accept-admin-invitation#').http_build_query([
            'invitation_id' => $invitation->id, 'email' => $invitation->email, 'token' => $proof['token'],
        ], encoding_type: PHP_QUERY_RFC3986);

        return new ActivateAdminAccount($url, $first);
    }
}
