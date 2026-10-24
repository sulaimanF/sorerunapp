<?php

declare(strict_types=1);

namespace App\Data\Members;

use SensitiveParameter;

final readonly class UpdateMemberProfileData
{
    /** @param array<string, string|null> $changes Validated editable fields only. */
    public function __construct(#[SensitiveParameter] public array $changes) {}
}
