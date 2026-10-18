<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateEmailVerificationSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->query('signature');
        $expires = $request->query('expires');
        $version = $request->query('v');
        if (! is_string($signature) || ! preg_match('/\A[a-f0-9]{64}\z/D', $signature)
            || ! is_string($expires) || ! preg_match('/\A[0-9]{1,12}\z/D', $expires)
            || ! is_string($version) || ! preg_match('/\A[1-9][0-9]{0,9}\z/D', $version)
            || ! $request->hasValidSignature()) {
            throw new ApiProblem('INVALID_VERIFICATION_LINK', 'Tautan verifikasi tidak valid atau sudah kedaluwarsa.', 403);
        }

        return $next($request);
    }
}
