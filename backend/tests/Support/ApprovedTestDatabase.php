<?php

declare(strict_types=1);

namespace Tests\Support;

final class ApprovedTestDatabase
{
    public static function name(): string
    {
        // Only this explicit isolated regression scope changes the historical test target.
        return getenv('SORERUNAPP_REGRESSION_SCOPE') === 'stage11' ? 'sorerunapp_stage11_test' : 'sorerunapp_test';
    }
}
