<?php

declare(strict_types=1);

use App\Actions\Content\DeleteLandingMedia;
use App\Actions\Content\PublishLandingPage;
use App\Actions\Content\SaveLandingDraft;
use App\Exceptions\ApiProblem;
use App\Models\LandingPage;
use App\Services\Content\LandingContentAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'sorerunapp_test'
        || ! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.4.') || config('cache.default') !== 'array'
        || ! preg_match('/\A[a-f0-9]{32}\z/D', $input['run'] ?? '') || ! in_array($input['worker'] ?? null, [1, 2], true)) {
        throw new RuntimeException('Invalid isolated race context.');
    }
    if (DB::selectOne('SELECT DATABASE() AS db_name')->db_name !== 'sorerunapp_test') {
        throw new RuntimeException('Wrong actual database.');
    }
    DB::statement("SET SESSION time_zone = '+00:00'");
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    config(['filesystems.disks.landing.root' => storage_path('framework/testing/landing-concurrency-media')]);
    $directory = storage_path('framework/testing/landing-race-'.$input['run']);
    if (! is_dir($directory)) {
        throw new RuntimeException('No barrier.');
    }
    $connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($directory.'/ready-'.$input['worker'], 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/go')) {
        clearstatcache();
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier expired.');
        } usleep(10000);
    }
    $task = $input['task'];
    $waitFor = static function (string $file): void {
        $deadline = microtime(true) + 25;
        while (! is_file($file)) {
            clearstatcache();
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Lock-order barrier expired.');
            }
            usleep(10000);
        }
    };
    try {
        if ($task['operation'] === 'publish_and_clear') {
            $revision = DB::transaction(function () use ($task, $directory, $waitFor) {
                app(LandingContentAccess::class)->lockPage($task['actor'], 1);
                file_put_contents($directory.'/page-locked', 'ready');
                $waitFor($directory.'/reader-ready');
                $revision = app(PublishLandingPage::class)->handle($task['actor'], 1, $task['version'], (string) Str::uuid());
                $content = LandingPage::findOrFail(1)->draft;
                $content['hero']['image'] = null;
                app(SaveLandingDraft::class)->handle($task['actor'], 1, $task['version'], $content, (string) Str::uuid());

                return $revision;
            });
            $result = ['code' => 'published_and_cleared', 'revision' => $revision->id];
        } elseif ($task['operation'] === 'delete_after_lock') {
            $waitFor($directory.'/page-locked');
            // Gate's non-locking roles read establishes the reader's RR snapshot
            // before it waits for the page lock and the writer's new media pivot.
            DB::listen(static function ($query) use ($directory): void {
                if (str_contains($query->sql, 'from `roles`')) {
                    file_put_contents($directory.'/reader-ready', 'ready');
                }
            });
            app(DeleteLandingMedia::class)->handle($task['actor'], 1, $task['media'], (string) Str::uuid());
            $result = ['code' => 'deleted'];
        } elseif ($task['operation'] === 'publish') {
            $revision = app(PublishLandingPage::class)->handle($task['actor'], 1, $task['version'], (string) Str::uuid());
            $result = ['code' => 'published', 'revision' => $revision->id];
        } elseif ($task['operation'] === 'save') {
            app(SaveLandingDraft::class)->handle($task['actor'], 1, $task['version'], $task['content'], (string) Str::uuid());
            $result = ['code' => 'saved'];
        } elseif ($task['operation'] === 'delete') {
            app(DeleteLandingMedia::class)->handle($task['actor'], 1, $task['media'], (string) Str::uuid());
            $result = ['code' => 'deleted'];
        } else {
            throw new RuntimeException('Unknown operation.');
        }
    } catch (ApiProblem $error) {
        $result = ['code' => $error->errorCode];
    } catch (ValidationException) {
        $result = ['code' => 'VALIDATION_FAILED'];
    }
    echo json_encode([...$result, 'connection' => $connection], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, 'Landing race failed: '.$error::class);
    exit(1);
}
