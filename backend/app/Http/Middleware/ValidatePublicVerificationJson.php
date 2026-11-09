<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class ValidatePublicVerificationJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }
        if (! $request->isJson()) {
            throw new ApiProblem('UNSUPPORTED_MEDIA_TYPE', 'Gunakan format JSON.', 415);
        }
        if (strlen($request->getContent()) > 4096) {
            throw new ApiProblem('PAYLOAD_TOO_LARGE', 'Ukuran permintaan terlalu besar.', 413);
        }
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR, depth: 8);
        } catch (JsonException) {
            throw new ApiProblem('INVALID_JSON', 'Isi JSON tidak valid.', 400);
        }
        if (! $body instanceof stdClass) {
            throw new ApiProblem('INVALID_JSON', 'Isi JSON harus berupa objek.', 400);
        }
        $resend = $request->is('api/v1/auth/email-verification/resend');
        if ($resend && $request->query() !== []) {
            throw new ApiProblem('VALIDATION_FAILED', 'Email harus dikirim melalui body JSON.', 422);
        }
        $allowed = $resend ? ['email'] : [];
        if (array_diff(array_keys(get_object_vars($body)), $allowed) !== []) {
            throw new ApiProblem('VALIDATION_FAILED', 'Permintaan berisi field yang tidak diizinkan.', 422);
        }

        return $next($request);
    }
}
