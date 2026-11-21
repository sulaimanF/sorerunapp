<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

final class ValidateAdminAccountJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $deletion = $request->isMethod('DELETE') && $request->is('api/v1/admin/accounts/*');
        if (! $deletion && (! $request->isMethod('POST') || ! ($request->is('api/v1/admin/accounts', 'api/v1/admin/accounts/*/invitation/resend', 'api/v1/admin/accounts/*/password', 'api/v1/me/password', 'api/v1/auth/admin-invitations/accept', 'api/v1/auth/first-account-activation/accept')))) {
            return $next($request);
        }
        if (! $request->isJson()) {
            throw new ApiProblem('UNSUPPORTED_MEDIA_TYPE', 'Gunakan format JSON untuk permintaan ini.', 415);
        }
        if (strlen($request->getContent()) > 8192) {
            throw new ApiProblem('PAYLOAD_TOO_LARGE', 'Ukuran permintaan terlalu besar.', 413);
        }
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR, depth: 8);
        } catch (JsonException) {
            throw new ApiProblem('INVALID_JSON', 'Isi JSON permintaan tidak valid.', 400);
        }
        if (! $body instanceof stdClass) {
            throw new ApiProblem('INVALID_JSON', 'Isi JSON harus berupa objek.', 400);
        }

        return $next($request);
    }
}
