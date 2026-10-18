<?php

declare(strict_types=1);

namespace App\Data\Auth;

use SensitiveParameter;

final readonly class RegisterMemberData
{
    public function __construct(
        public string $fullName,
        public string $email,
        #[SensitiveParameter] public string $password,
        public string $birthDate,
        public string $phone,
        public string $city,
        public ?string $gender,
        public string $privacyNoticeVersion,
        public string $termsVersion,
    ) {}
}
