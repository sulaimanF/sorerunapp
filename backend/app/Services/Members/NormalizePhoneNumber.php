<?php

declare(strict_types=1);

namespace App\Services\Members;

class NormalizePhoneNumber
{
    public function handle(string $value): string
    {
        $number = preg_replace('/[\s().-]+/u', '', trim($value)) ?? $value;

        if (str_starts_with($number, '00')) {
            return '+'.substr($number, 2);
        }

        if (str_starts_with($number, '0')) {
            return '+62'.substr($number, 1);
        }

        if (str_starts_with($number, '62')) {
            return '+'.$number;
        }

        return $number;
    }
}
