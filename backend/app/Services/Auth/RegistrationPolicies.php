<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiProblem;

class RegistrationPolicies
{
    /** @return array{privacy_notice: string, terms: string} */
    public function current(): array
    {
        $privacy = config('sorerunapp.registration.privacy_notice_version');
        $terms = config('sorerunapp.registration.terms_version');

        foreach ([$privacy, $terms] as $version) {
            if (! is_string($version) || trim($version) === '' || mb_strlen($version) > 64) {
                throw new ApiProblem('REGISTRATION_UNAVAILABLE', 'Layanan registrasi belum tersedia. Silakan coba kembali nanti.', 503);
            }
        }

        return ['privacy_notice' => $privacy, 'terms' => $terms];
    }
}
