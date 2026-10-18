<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Exceptions\ApiProblem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*', 'sanctum/*')) {
            return null;
        }

        if ($exception instanceof ApiProblem) {
            return ApiErrorResponse::make($request, $exception->errorCode, $exception->getMessage(), $exception->status, $exception->fields);
        }

        if ($exception instanceof ValidationException) {
            return ApiErrorResponse::make($request, 'VALIDATION_FAILED', 'Periksa kembali data yang Anda isi.', 422, $exception->errors());
        }

        if ($exception instanceof AuthenticationException) {
            return ApiErrorResponse::make($request, 'UNAUTHENTICATED', 'Silakan masuk untuk melanjutkan.', 401);
        }

        if ($exception instanceof AuthorizationException) {
            return ApiErrorResponse::make($request, 'FORBIDDEN', 'Anda tidak memiliki akses untuk tindakan ini.', 403);
        }

        if ($exception instanceof QueryException) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $temporary = in_array($driverCode, [1040, 1045, 1049, 1203, 1205, 1213, 2002, 2003, 2006, 2013], true)
                || str_starts_with((string) ($exception->errorInfo[0] ?? ''), '08');

            return ApiErrorResponse::make(
                $request,
                $temporary ? 'SERVICE_UNAVAILABLE' : 'SERVER_ERROR',
                $temporary ? 'Layanan sedang tidak tersedia. Silakan coba kembali.' : 'Terjadi kesalahan pada server.',
                $temporary ? 503 : 500,
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            [$code, $message] = match ($status) {
                400 => ['BAD_REQUEST', 'Permintaan tidak valid.'],
                401 => ['UNAUTHENTICATED', 'Silakan masuk untuk melanjutkan.'],
                403 => ['FORBIDDEN', 'Anda tidak memiliki akses untuk tindakan ini.'],
                404 => ['NOT_FOUND', 'Data atau halaman tidak ditemukan.'],
                405 => ['METHOD_NOT_ALLOWED', 'Metode permintaan tidak didukung.'],
                413 => ['PAYLOAD_TOO_LARGE', 'Ukuran permintaan terlalu besar.'],
                415 => ['UNSUPPORTED_MEDIA_TYPE', 'Gunakan format JSON untuk permintaan ini.'],
                419 => ['CSRF_MISMATCH', 'Sesi permintaan tidak valid. Muat ulang halaman lalu coba kembali.'],
                429 => ['RATE_LIMITED', 'Terlalu banyak percobaan. Silakan tunggu sebelum mencoba kembali.'],
                503 => ['SERVICE_UNAVAILABLE', 'Layanan sedang tidak tersedia. Silakan coba kembali.'],
                default => ['HTTP_ERROR', 'Permintaan tidak dapat diproses.'],
            };

            return ApiErrorResponse::make($request, $code, $message, $status, headers: $exception->getHeaders());
        }

        return ApiErrorResponse::make($request, 'SERVER_ERROR', 'Terjadi kesalahan pada server.', 500);
    }
}
