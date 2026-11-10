<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiProblem;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ResetMemberPasswordMail;
use Illuminate\Support\Facades\Password;

class PasswordResetNotification
{
    public function forDelivery(NotificationDelivery $delivery, User $user): ?ResetMemberPasswordMail
    {
        $payload = $delivery->payload_encrypted;
        if (! is_array($payload) || ! is_string($payload['email'] ?? null) || ! is_string($payload['token'] ?? null)
            || ($payload['auth_version'] ?? null) !== $user->auth_version || ! hash_equals($user->email, $payload['email'])
            || ! Password::broker('users')->tokenExists($user, $payload['token'])) {
            return null;
        }
        $root = (string) config('sorerunapp.frontend_url');
        $parts = parse_url($root);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new ApiProblem('EMAIL_CONFIGURATION_UNAVAILABLE', 'Konfigurasi email belum tersedia.', 503);
        }
        // Never create a token in a delivery retry. A delayed email may contain an invalid link, never a revived one.
        $url = rtrim($root, '/').'/reset-password#'.http_build_query([
            'email' => $payload['email'], 'token' => $payload['token'],
        ], encoding_type: PHP_QUERY_RFC3986);

        return new ResetMemberPasswordMail($url, (int) config('auth.passwords.users.expire'));
    }
}
