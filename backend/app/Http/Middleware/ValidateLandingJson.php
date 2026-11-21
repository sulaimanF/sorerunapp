<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class ValidateLandingJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson()) {
            throw new ApiProblem('UNSUPPORTED_MEDIA_TYPE', 'Gunakan format JSON.', 415);
        }
        if (strlen($request->getContent()) > 131072) {
            throw new ApiProblem('PAYLOAD_TOO_LARGE', 'Konten terlalu besar.', 413);
        }
        try {
            $body = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiProblem('INVALID_JSON', 'JSON tidak valid.', 400);
        }
        if (! $body instanceof stdClass) {
            throw new ApiProblem('INVALID_JSON', 'Body harus objek JSON.', 400);
        }

        return $next($request);
    }
}
