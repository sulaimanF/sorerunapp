<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterMember;
use App\Data\Auth\RegisterMemberData;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $run = $input['run'] ?? '';
    $worker = $input['worker'] ?? '';
    if (! is_string($run) || ! preg_match('/^[a-f0-9]{32}$/D', $run) || ! in_array($worker, ['1', '2'], true)) {
        throw new RuntimeException('Invalid synthetic test identifier.');
    }

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = DB::connection();
    if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
        || $connection->getDatabaseName() !== 'sorerunapp_test'
        || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
        throw new RuntimeException('Worker requires the isolated MySQL 8.4 test database.');
    }

    DB::statement("SET SESSION time_zone = '+00:00'");
    $directory = storage_path('framework/testing/registration-race-'.$run);
    if (! is_dir($directory)) {
        throw new RuntimeException('Test barrier directory is missing.');
    }

    $email = 'registration-race-'.$run.'@example.test';
    User::creating(function (User $user) use ($email, $directory, $worker): void {
        if ($user->email !== $email) {
            return;
        }

        // Both workers must pass the existence check before either INSERT starts.
        if (file_put_contents($directory.'/ready-'.$worker, 'ready') === false) {
            throw new RuntimeException('Cannot create the test barrier.');
        }
        $deadline = microtime(true) + 25;
        while (! is_file($directory.'/go')) {
            clearstatcache();
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Test barrier timed out.');
            }
            usleep(10_000);
        }
    });

    $app->make(RegisterMember::class)->handle(new RegisterMemberData(
        fullName: 'Concurrent Test Member',
        email: $email,
        password: 'Test-only-password-123',
        birthDate: '2000-01-01',
        phone: '+6281234567890',
        city: 'Kota Uji',
        gender: null,
        privacyNoticeVersion: 'test-v1',
        termsVersion: 'test-v1',
    ), (string) Str::uuid());

    fwrite(STDOUT, json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    // Never print SQL, bindings, credentials, or exception messages from workers.
    fwrite(STDERR, json_encode(['error_class' => $exception::class], JSON_THROW_ON_ERROR));
    exit(1);
}
