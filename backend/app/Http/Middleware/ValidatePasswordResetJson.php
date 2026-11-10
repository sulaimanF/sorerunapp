<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class ValidatePasswordResetJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('api/v1/auth/forgot-password', 'api/v1/auth/reset-password')) {
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
        $allowed = $request->is('api/v1/auth/forgot-password') ? ['email'] : ['email', 'token', 'password', 'password_confirmation'];
        if ($request->query() !== [] || array_diff(array_keys(get_object_vars($body)), $allowed) !== []) {
            throw new ApiProblem('VALIDATION_FAILED', 'Gunakan hanya field yang diizinkan dalam body JSON.', 422);
        }

        return $next($request);
    }
}
