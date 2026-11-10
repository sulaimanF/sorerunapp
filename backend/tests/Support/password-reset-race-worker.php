<?php

declare(strict_types=1);

use App\Actions\Auth\ResetMemberPassword;
use App\Exceptions\ApiProblem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! preg_match('/\A[a-f0-9]{32}\z/D', (string) ($input['run'] ?? ''))
        || ! in_array($input['worker'] ?? '', ['1', '2'], true)
        || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'sorerunapp_test'
        || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
        throw new RuntimeException('Invalid isolated concurrency worker context.');
    }
    config(['sorerunapp.outbox.dispatch_on_commit' => false, 'mail.default' => 'array']);
    $directory = storage_path('framework/testing/password-reset-race-'.$input['run']);
    if (! is_dir($directory) || file_put_contents($directory.'/ready-'.$input['worker'], 'ready') === false) {
        throw new RuntimeException('Cannot reach worker barrier.');
    }
    $deadline = microtime(true) + 20;
    while (! is_file($directory.'/go')) {
        clearstatcache();
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(10_000);
    }
    try {
        app(ResetMemberPassword::class)->handle($input['credentials'], (string) Str::uuid());
        $result = 'password_reset';
    } catch (ApiProblem $problem) {
        $result = $problem->errorCode;
    }
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Worker failed: '.$exception::class);
    exit(1);
}
