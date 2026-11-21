<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Exceptions\ApiProblem;
use App\Models\LandingPageMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Throwable;

final class StoreLandingImage
{
    public function __construct(private LandingContentAccess $access) {}

    public function handle(int $actorId, int $sessionVersion, UploadedFile $file, string $requestId): LandingPageMedia
    {
        $info = @getimagesize($file->getPathname());
        $mime = $file->getMimeType();
        if ($file->getSize() > 5 * 1024 * 1024 || $info === false || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ($info['mime'] ?? '') !== $mime
            || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 16000000) {
            throw ValidationException::withMessages(['image' => ['Gunakan JPEG/PNG/WebP maksimal 5 MB, dimensi 16–6000 px dan maksimal 16 megapixel.']]);
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp') || ! (imagetypes() & IMG_WEBP)) {
            throw new ApiProblem('IMAGE_PROCESSOR_UNAVAILABLE', 'Pemrosesan gambar belum tersedia. Hubungi pengelola.', 503);
        }
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 10 + $file->getSize() * 2 + 16777216 > $limit) {
            throw ValidationException::withMessages(['image' => ['Resolusi gambar terlalu besar untuk diproses. Perkecil gambar dan unggah kembali.']]);
        }
        $source = @imagecreatefromstring($file->getContent());
        if ($source === false) {
            throw ValidationException::withMessages(['image' => ['Isi gambar tidak dapat dibaca.']]);
        }
        $scale = min(1, 2400 / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            imagedestroy($source);
            throw new ApiProblem('IMAGE_PROCESSING_FAILED', 'Gambar belum dapat diproses.', 503);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        if (! imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1])) {
            imagedestroy($image);
            imagedestroy($source);
            throw new ApiProblem('IMAGE_PROCESSING_FAILED', 'Gambar belum dapat diproses.', 503);
        }
        ob_start();
        try {
            if (! imagewebp($image, null, 85)) {
                throw new ApiProblem('IMAGE_PROCESSING_FAILED', 'Gambar belum dapat diproses.', 503);
            }
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
            imagedestroy($source);
        }
        if (! is_string($bytes) || strlen($bytes) === 0 || strlen($bytes) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => ['Perkecil gambar sebelum mengunggah.']]);
        }
        $id = (string) Str::uuid();
        $path = 'images/'.$id.'.webp';
        $disk = Storage::disk('landing');
        // File operations precede/follow transactions; never perform storage I/O under row locks.
        try {
            if (! $disk->put($path, $bytes)) {
                throw new ApiProblem('MEDIA_STORAGE_UNAVAILABLE', 'Gambar belum dapat disimpan.', 503);
            }

            return DB::transaction(function () use ($actorId, $sessionVersion, $id, $path, $bytes, $width, $height, $requestId): LandingPageMedia {
                $this->access->lockPage($actorId, $sessionVersion);
                $media = (new LandingPageMedia)->forceFill([
                    'public_id' => $id, 'path' => $path, 'mime' => 'image/webp', 'size' => strlen($bytes),
                    'width' => $width, 'height' => $height, 'state' => 'ready', 'uploaded_by' => $actorId, 'created_at' => now('UTC'),
                ]);
                $media->save();
                $this->access->audit($actorId, 'content.media_uploaded', ['media_id' => $media->id], $requestId);

                return $media;
            }, attempts: 3);
        } catch (Throwable $error) {
            try {
                // A lost commit acknowledgement must never remove an attached file.
                if (! LandingPageMedia::query()->where('public_id', $id)->exists() && ! $disk->delete($path)) {
                    Log::warning('Landing media orphan cleanup required.', ['media_reference' => $id]);
                }
            } catch (Throwable) {
                // Random reference maps to a deterministic private path for manual recovery.
                Log::warning('Landing media orphan cleanup required.', ['media_reference' => $id]);
            }
            if ($error instanceof FilesystemException) {
                throw new ApiProblem('MEDIA_STORAGE_UNAVAILABLE', 'Penyimpanan gambar belum tersedia. Silakan coba kembali.', 503);
            }
            throw $error;
        }
    }
}
