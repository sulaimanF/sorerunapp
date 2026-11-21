<?php

declare(strict_types=1);

use App\Actions\Content\PublishLandingPage;
use App\Actions\Content\SaveLandingDraft;
use App\Models\LandingPage;
use App\Models\LandingPageMedia;
use App\Models\LandingPageRevision;
use App\Models\Role;
use App\Models\User;
use App\Services\Content\LandingDocument;
use App\Services\Content\StoreLandingImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/stage10-environment.php';

try {
    bootStage10(live: true, manual: true);
    $operation = $argv[1] ?? 'status';
    if (! in_array($operation, ['prepare', 'status'], true)) {
        throw new RuntimeException('Use prepare or status; this helper never migrates or cleans up.');
    }
    if (! DB::table('migrations')->where('migration', '2026_11_14_120000_create_landing_page_content_tables')->exists()
        || ! extension_loaded('gd') || ! function_exists('imagewebp') || ! (imagetypes() & IMG_WEBP)) {
        throw new RuntimeException('Stage10 schema and actual GD/WebP support are required.');
    }
    $directory = storage_path('framework/testing/stage10-manual');
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Manual state directory unavailable.');
    }
    $lock = fopen($directory.'/setup.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another manual setup is running.');
    }
    $statePath = $directory.'/state.json';
    $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR) : null;
    $persist = static function (array $state) use ($statePath): void {
        // This file deliberately contains no password, session or environment secrets.
        if (file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new RuntimeException('Cannot retain manual fixture ownership.');
        }
    };
    $created = false;
    if ($operation === 'prepare' && $state === null) {
        $page = LandingPage::findOrFail(1);
        if ($page->draft_version !== 0 || $page->draft !== null || $page->published_revision_id !== null || LandingPageRevision::query()->exists()) {
            throw new RuntimeException('Existing landing content belongs to another test; refusing overwrite.');
        }
        $role = Role::query()->where('code', 'admin')->sole();
        $password = 'Manual-'.bin2hex(random_bytes(12)).'!';
        $email = 'stage10-manual-'.bin2hex(random_bytes(6)).'@example.test';
        $state = ['purpose' => 'stage10-manual', 'phase' => 'creating', 'email' => $email, 'actor_id' => null, 'media_ids' => []];
        $persist($state);
        $user = DB::transaction(function () use ($role, $email, $password): User {
            $user = User::factory()->create(['name' => 'Admin Uji Manual Stage 10', 'email' => $email,
                'email_verified_at' => now('UTC'), 'password' => $password, 'remember_token' => null]);
            $user->memberProfile()->create(['full_name' => 'Admin Uji Manual Stage 10', 'birth_date' => '2000-01-01',
                'phone' => '+620000000000', 'city' => 'Kota Uji Sintetis']);
            $user->roles()->attach($role->id, ['assigned_by' => null, 'assigned_at' => now('UTC')]);

            return $user;
        });
        $state['actor_id'] = $user->id;
        $persist($state);
        $pictures = [];
        foreach (['orange', 'gold', 'cream'] as $index => $name) {
            $path = $directory.'/sample-'.$name.'.png';
            $image = imagecreatetruecolor(1200, 720);
            $colors = [[234, 97, 19], [251, 185, 49], [255, 227, 179]];
            imagefill($image, 0, 0, imagecolorallocate($image, ...$colors[$index]));
            $ink = imagecolorallocate($image, 43, 33, 24);
            imagefilledellipse($image, 850, 260, 300, 300, $ink);
            imagerectangle($image, 60, 60, 1139, 659, $ink);
            imagestring($image, 5, 100, 450, 'STAGE 10 - DATA UJI SINTETIS', $ink);
            imagestring($image, 5, 100, 480, 'BUKAN FOTO ATAU KONTEN RESMI KOMUNITAS', $ink);
            if (! imagepng($image, $path)) {
                throw new RuntimeException('Synthetic image generation failed.');
            }
            imagedestroy($image);
            $media = app(StoreLandingImage::class)->handle($user->id, $user->auth_version,
                new UploadedFile($path, 'sample-'.$name.'.png', 'image/png', null, true), (string) Str::uuid());
            $state['media_ids'][] = $media->public_id;
            $persist($state);
            $pictures[] = ['media_id' => $media->public_id, 'alt' => 'Ilustrasi geometris sintetis '.$name.' untuk pengujian',
                'caption' => 'DATA UJI — ilustrasi sintetis, bukan foto komunitas.'];
        }
        $content = app(LandingDocument::class)->empty();
        $content['hero'] = ['eyebrow' => 'LINGKUNGAN TEST STAGE 10', 'title' => 'Ruang uji landing page SRC',
            'description' => 'Seluruh teks dan gambar di halaman ini adalah data sintetis untuk mencoba editor. Bukan publikasi resmi, jadwal kegiatan, atau informasi anggota.',
            'login_label' => 'Masuk ke Aplikasi', 'image' => $pictures[0]];
        $content['about'] = ['title' => 'Tentang komunitas — data uji', 'description' => 'Contoh section untuk menguji cerita dan nilai komunitas. Silakan ubah teks ini dari editor admin.',
            'items' => [['title' => 'Ruang kebersamaan', 'description' => 'Contoh kartu editorial sintetis.'],
                ['title' => 'Langkah yang nyaman', 'description' => 'Contoh nilai komunitas untuk pengujian tampilan.']]];
        $content['activities'] = ['title' => 'Kegiatan — contoh editorial', 'description' => 'Section ini tidak terhubung ke event internal, RSVP, pembayaran, atau jadwal nyata.',
            'items' => [['title' => 'Contoh lari santai', 'description' => 'Ilustrasi informasi kegiatan, bukan undangan atau jadwal resmi.'],
                ['title' => 'Contoh berbagi cerita', 'description' => 'Konten sintetis untuk mencoba pengurutan dan visibilitas section.']]];
        $content['gallery'] = ['title' => 'Galeri ilustrasi sintetis', 'items' => $pictures];
        $content['faq'] = ['title' => 'FAQ pengujian manual', 'items' => [
            ['question' => 'Apakah ini situs development utama?', 'answer' => 'Tidak. Halaman ini memakai database sorerunapp_test dan storage test terpisah.'],
            ['question' => 'Kapan perubahan terlihat publik?', 'answer' => 'Setelah menyimpan draft dan melakukan publish manual dari editor.'],
            ['question' => 'Apakah data akan dihapus saat server berhenti?', 'answer' => 'Tidak. Stop dan start launcher tidak mereset akun, konten, atau gambar.'],
        ]];
        $content['contact'] = ['title' => 'Kontak contoh — tidak aktif', 'description' => 'Alamat dan tautan berikut khusus pengujian dan tidak mewakili kontak resmi SRC.',
            'email' => 'kontak-stage10@example.test', 'phone' => '', 'socials' => [['label' => 'Media sosial contoh (test)', 'url' => 'https://example.test/komunitas-uji']]];
        $content['footer'] = ['text' => 'LINGKUNGAN TEST — Data sintetis, bukan konten resmi SRC.'];
        $content['seo'] = ['title' => 'Stage 10 — Uji Manual sorerunapp', 'description' => 'Lingkungan test terisolasi untuk mencoba landing page dan editor admin. Semua konten dan gambar bersifat sintetis.', 'image' => $pictures[0]];
        $version = app(SaveLandingDraft::class)->handle($user->id, $user->auth_version, 0, $content, (string) Str::uuid());
        $revision = app(PublishLandingPage::class)->handle($user->id, $user->auth_version, $version, (string) Str::uuid());
        $state['phase'] = 'ready';
        $state['initial_revision_id'] = $revision->id;
        $persist($state);
        $created = true;
    }
    $actor = $state === null ? null : User::query()->whereKey($state['actor_id'])->where('email', $state['email'])->first();
    $page = LandingPage::findOrFail(1);
    echo json_encode(['environment' => app()->environment(), 'database' => DB::selectOne('SELECT DATABASE() AS db_name')->db_name,
        'prepared' => $state !== null && $state['phase'] === 'ready' && $actor !== null && $actor->roles()->where('code', 'admin')->exists(),
        'phase' => $state['phase'] ?? 'not_prepared', 'created' => $created, 'email' => $actor?->email,
        ...($created ? ['password' => $password] : []),
        'draft_version' => $page->draft_version, 'publication_id' => $page->published_revision_id,
        'manual_media' => $actor === null ? 0 : LandingPageMedia::where('uploaded_by', $actor->id)->count(),
        'migration_batch' => DB::table('migrations')->where('migration', '2026_11_14_120000_create_landing_page_content_tables')->value('batch'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Preserve partial synthetic fixtures for diagnosis; never clean other tests.
    fwrite(STDERR, 'Manual setup stopped: '.$error::class.PHP_EOL);
    exit(1);
}
