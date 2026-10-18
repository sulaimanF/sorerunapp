<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiErrorResponse
{
    /**
     * @param  array<string, array<int, string>>  $fields
     * @param  array<string, mixed>  $headers
     */
    public static function make(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $fields = [],
        array $headers = [],
    ): JsonResponse {
        $requestId = RequestId::get($request);

        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields],
            'meta' => ['request_id' => $requestId],
        ], $status, array_merge($headers, [
            'Cache-Control' => 'no-store',
            'X-Request-ID' => $requestId,
        ]));
    }
}
