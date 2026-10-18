<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ApiProblem extends RuntimeException implements ShouldntReport
{
    /** @param array<string, array<int, string>> $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
