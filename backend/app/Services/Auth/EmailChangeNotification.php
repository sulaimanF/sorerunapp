<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiProblem;
use App\Models\EmailChangeRequest;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\VerifyChangedEmail;
use SensitiveParameter;

class EmailChangeNotification
{
    public function currentRequest(NotificationDelivery $delivery, User $user): ?EmailChangeRequest
    {
        $payload = $delivery->payload_encrypted;
        if (! is_array($payload) || ! is_string($payload['request_id'] ?? null) || ! is_string($payload['token'] ?? null)) {
            return null;
        }
        $change = EmailChangeRequest::query()->whereKey($payload['request_id'])->where('user_id', $user->id)->first();
        if ($change === null || $change->revoked_at !== null || $change->consumed_at !== null
            || $change->expires_at->lessThanOrEqualTo(now('UTC')) || $change->user_version !== $user->auth_version
            || ! hash_equals($change->token_hash, hash('sha256', $payload['token']))) {
            return null;
        }

        return $change;
    }

    public function notification(EmailChangeRequest $change, #[SensitiveParameter] string $token): VerifyChangedEmail
    {
        $root = (string) config('sorerunapp.frontend_url');
        $parts = parse_url($root);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new ApiProblem('EMAIL_CONFIGURATION_UNAVAILABLE', 'Konfigurasi email belum tersedia.', 503);
        }
        $url = rtrim($root, '/').'/verify-email-change#'.http_build_query([
            'request_id' => $change->id, 'email' => $change->new_email, 'token' => $token,
        ], encoding_type: PHP_QUERY_RFC3986);

        return new VerifyChangedEmail($url);
    }
}
