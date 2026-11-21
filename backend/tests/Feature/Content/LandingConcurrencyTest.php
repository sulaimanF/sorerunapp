<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Actions\Content\SaveLandingDraft;
use App\Models\LandingPage;
use App\Models\LandingPageMedia;
use App\Models\LandingPageRevision;
use App\Services\Content\StoreLandingImage;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\LandingFixtures;
use Tests\TestCase;

final class LandingConcurrencyTest extends TestCase
{
    use LandingFixtures;

    private array $actors = [];

    private bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireLandingDatabase();
        if (LandingPageRevision::query()->exists() || LandingPage::query()->findOrFail(1)->draft_version !== 0) {
            throw new RuntimeException('Concurrency tests require the initial isolated landing singleton.');
        }
        $this->seed(CoreRolesSeeder::class);
        $this->actors = [$this->contentAdmin()->id, $this->contentAdmin()->id];
        config(['filesystems.disks.landing.root' => storage_path('framework/testing/landing-concurrency-media')]);
        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->prepared) {
                $ids = LandingPageRevision::query()->whereIn('published_by', $this->actors)->pluck('id');
                LandingPage::query()->whereKey(1)->update(['draft' => null, 'draft_version' => 0, 'published_revision_id' => null, 'updated_by' => null]);
                DB::table('landing_page_revision_media')->whereIn('revision_id', $ids)->delete();
                DB::table('landing_page_revisions')->whereIn('id', $ids)->delete();
                foreach (LandingPageMedia::query()->whereIn('uploaded_by', $this->actors)->get() as $media) {
                    Storage::disk('landing')->delete($media->path);
                    $media->delete();
                }
                DB::table('audit_logs')->where('target_type', 'landing_page')->whereIn('actor_user_id', $this->actors)->delete();
                DB::table('user_roles')->whereIn('user_id', $this->actors)->delete();
                DB::table('users')->whereIn('id', $this->actors)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_publish_is_one_snapshot_and_one_audit(): void
    {
        app(SaveLandingDraft::class)->handle($this->actors[0], 1, 0, $this->publishableContent(), (string) Str::uuid());
        $results = $this->race([
            ['operation' => 'publish', 'actor' => $this->actors[0], 'version' => 1],
            ['operation' => 'publish', 'actor' => $this->actors[1], 'version' => 1],
        ]);
        self::assertSame($results[0]['revision'], $results[1]['revision']);
        self::assertSame(1, LandingPageRevision::query()->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'content.published')->whereIn('actor_user_id', $this->actors)->count());
    }

    public function test_parallel_save_has_exactly_one_winner(): void
    {
        $results = $this->race([
            ['operation' => 'save', 'actor' => $this->actors[0], 'version' => 0, 'content' => $this->publishableContent()],
            ['operation' => 'save', 'actor' => $this->actors[1], 'version' => 0, 'content' => [...$this->publishableContent(), 'footer' => ['text' => 'Alternatif sintetis']]],
        ]);
        $codes = array_column($results, 'code');
        sort($codes);
        self::assertSame(['CONTENT_VERSION_CONFLICT', 'saved'], $codes);
        self::assertSame(1, LandingPage::query()->findOrFail(1)->draft_version);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'content.draft_saved')->whereIn('actor_user_id', $this->actors)->count());
    }

    public function test_publish_racing_new_draft_never_publishes_unspecified_version(): void
    {
        app(SaveLandingDraft::class)->handle($this->actors[0], 1, 0, $this->publishableContent(), (string) Str::uuid());
        $next = $this->publishableContent();
        $next['hero']['title'] = 'Versi baru belum disetujui';
        $results = $this->race([
            ['operation' => 'publish', 'actor' => $this->actors[0], 'version' => 1],
            ['operation' => 'save', 'actor' => $this->actors[1], 'version' => 1, 'content' => $next],
        ]);
        self::assertSame('saved', $results[1]['code']);
        self::assertContains($results[0]['code'], ['published', 'CONTENT_VERSION_CONFLICT']);
        foreach (LandingPageRevision::query()->get() as $revision) {
            self::assertSame('Headline sintetis', $revision->content['hero']['title']);
        }
        self::assertSame(2, LandingPage::query()->findOrFail(1)->draft_version);
    }

    public function test_publish_racing_media_deletion_keeps_the_referenced_file(): void
    {
        $media = app(StoreLandingImage::class)->handle($this->actors[0], 1, UploadedFile::fake()->image('race.png', 64, 64), (string) Str::uuid());
        $content = $this->publishableContent();
        $content['hero']['image'] = ['media_id' => $media->public_id, 'alt' => 'Gambar race sintetis', 'caption' => ''];
        app(SaveLandingDraft::class)->handle($this->actors[0], 1, 0, $content, (string) Str::uuid());
        $results = $this->race([
            ['operation' => 'publish', 'actor' => $this->actors[0], 'version' => 1],
            ['operation' => 'delete', 'actor' => $this->actors[1], 'media' => $media->public_id],
        ]);
        self::assertSame(['published', 'MEDIA_IN_USE'], array_column($results, 'code'));
        self::assertTrue(Storage::disk('landing')->exists($media->path));
        self::assertSame(1, DB::table('landing_page_revision_media')->where('media_id', $media->id)->count());
    }

    public function test_adding_a_draft_reference_racing_deletion_cannot_leave_a_broken_reference(): void
    {
        $media = app(StoreLandingImage::class)->handle($this->actors[0], 1, UploadedFile::fake()->image('race.png', 64, 64), (string) Str::uuid());
        $content = $this->publishableContent();
        $content['hero']['image'] = ['media_id' => $media->public_id, 'alt' => 'Gambar race sintetis', 'caption' => ''];
        $results = $this->race([
            ['operation' => 'save', 'actor' => $this->actors[0], 'version' => 0, 'content' => $content],
            ['operation' => 'delete', 'actor' => $this->actors[1], 'media' => $media->public_id],
        ]);
        if ($results[0]['code'] === 'saved') {
            self::assertSame('MEDIA_IN_USE', $results[1]['code']);
            self::assertSame($media->public_id, LandingPage::findOrFail(1)->draft['hero']['image']['media_id']);
            self::assertTrue(Storage::disk('landing')->exists($media->path));
        } else {
            self::assertSame(['VALIDATION_FAILED', 'deleted'], array_column($results, 'code'));
            self::assertNull(LandingPage::findOrFail(1)->draft);
            self::assertFalse(Storage::disk('landing')->exists($media->path));
            self::assertNull($media->fresh());
        }
    }

    public function test_retained_publication_protects_media_after_waiting_on_a_newer_page_version(): void
    {
        $media = app(StoreLandingImage::class)->handle($this->actors[0], 1, UploadedFile::fake()->image('race.png', 64, 64), (string) Str::uuid());
        $content = $this->publishableContent();
        $content['hero']['image'] = ['media_id' => $media->public_id, 'alt' => 'Gambar revisi dipertahankan', 'caption' => ''];
        app(SaveLandingDraft::class)->handle($this->actors[0], 1, 0, $content, (string) Str::uuid());
        $results = $this->race([
            ['operation' => 'publish_and_clear', 'actor' => $this->actors[0], 'version' => 1],
            ['operation' => 'delete_after_lock', 'actor' => $this->actors[1], 'media' => $media->public_id],
        ]);
        self::assertSame(['published_and_cleared', 'MEDIA_IN_USE'], array_column($results, 'code'));
        self::assertNull(LandingPage::findOrFail(1)->draft['hero']['image']);
        self::assertSame(2, LandingPage::findOrFail(1)->draft_version);
        self::assertTrue(Storage::disk('landing')->exists($media->path));
        self::assertSame('ready', $media->fresh()->state);
        self::assertSame(1, DB::table('landing_page_revision_media')->where('media_id', $media->id)->count());
    }

    private function race(array $tasks): array
    {
        $run = bin2hex(random_bytes(16));
        $directory = storage_path('framework/testing/landing-race-'.$run);
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot prepare barrier.');
        }
        $processes = [];
        try {
            foreach ($tasks as $i => $task) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/landing-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sorerunapp_test', 'DB_URL' => '', 'APP_DEBUG' => 'false',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'OUTBOX_DISPATCH_ON_COMMIT' => 'false',
                ]);
                $process->setInput(json_encode(['run' => $run, 'worker' => $i + 1, 'task' => $task], JSON_THROW_ON_ERROR));
                $process->setTimeout(40);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 25;
            while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
                clearstatcache();
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Barrier timed out.');
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Race worker failed before barrier.');
                    }
                }
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            self::assertNotSame($results[0]['connection'], $results[1]['connection']);

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (['go', 'ready-1', 'ready-2', 'page-locked', 'reader-ready'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }
    }
}
