<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class EnforceCsrfInRegistrationTests extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        // Turn OFF Laravel's testing shortcut, keeping real CSRF validation enabled.
        return false;
    }
}
