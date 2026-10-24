<?php

declare(strict_types=1);

use App\Actions\Members\UpdateMemberProfile;
use App\Actions\Memberships\VerifyEmailAndActivateMembership;
use App\Data\Members\UpdateMemberProfileData;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($input) || ! preg_match('/\A[a-f0-9]{32}\z/D', (string) ($input['run'] ?? ''))
        || ! in_array($input['worker'] ?? '', ['1', '2'], true)
        || ! in_array($input['mode'] ?? '', ['activation', 'profile'], true)
        || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || DB::connection()->getDatabaseName() !== 'sorerunapp_test'
        || ! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
        throw new RuntimeException('Invalid isolated concurrency worker context.');
    }
    config(['app.url' => 'http://localhost:8000', 'sorerunapp.membership.number_prefix' => 'TEST', 'sorerunapp.outbox.dispatch_on_commit' => false, 'mail.default' => 'array']);
    DB::statement("SET SESSION time_zone = '+00:00'");
    Notification::fake();
    $directory = storage_path('framework/testing/profile-race-'.$input['run']);
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
    if ($input['mode'] === 'activation') {
        $user = User::query()->findOrFail((int) $input['id']);
        $result = app(VerifyEmailAndActivateMembership::class)->handle((int) $user->id, (string) $user->id, sha1($user->email), (string) $user->auth_version, (string) Str::uuid(), $user->auth_version);
        echo json_encode(['result' => $result['result']], JSON_THROW_ON_ERROR);
    } else {
        $user = User::query()->findOrFail((int) $input['id']);
        app(UpdateMemberProfile::class)->handle((int) $user->id, $user->auth_version,
            new UpdateMemberProfileData(['city' => 'Kota Profil Paralel']), (string) Str::uuid());
        echo json_encode(['result' => 'profile_updated'], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    // Avoid printing SQL, bindings, email, signed link, or credential from failed workers.
    fwrite(STDERR, 'Worker failed: '.$exception::class);
    exit(1);
}
