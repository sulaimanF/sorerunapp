<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

class EmailVerificationUrl
{
    public function forUser(User $user): string
    {
        $root = (string) config('app.url');
        $parts = parse_url($root);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiProblem('EMAIL_CONFIGURATION_UNAVAILABLE', 'Konfigurasi email belum tersedia.', 503);
        }
        // A clone avoids changing the global URL generator or trusting the HTTP Host header.
        $urls = clone URL::getFacadeRoot();
        $urls->forceRootUrl($root);
        $urls->forceScheme($parts['scheme']);

        return $urls->temporarySignedRoute('api.v1.auth.email-verification.verify', CarbonImmutable::now('UTC')->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email), 'v' => $user->auth_version,
        ]);
    }
}
