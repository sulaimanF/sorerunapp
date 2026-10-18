<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class ValidateLoginJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('api/v1/auth/login')) {
            return $next($request);
        }
        if (! $request->isJson()) {
            throw new ApiProblem('UNSUPPORTED_MEDIA_TYPE', 'Gunakan format JSON untuk permintaan ini.', 415);
        }
        if (strlen($request->getContent()) > 16_384) {
            throw new ApiProblem('PAYLOAD_TOO_LARGE', 'Ukuran permintaan terlalu besar.', 413);
        }
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR, depth: 16);
        } catch (JsonException) {
            throw new ApiProblem('INVALID_JSON', 'Isi JSON permintaan tidak valid.', 400);
        }
        if (! $body instanceof stdClass) {
            throw new ApiProblem('INVALID_JSON', 'Isi JSON harus berupa objek.', 400);
        }

        return $next($request);
    }
}
