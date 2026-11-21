<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterMember;
use App\Data\Auth\RegisterMemberData;
use App\Models\User;
use App\Services\Notifications\LocalMailScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function existingSnapshot(array $ids): array
{
    $result = [];
    foreach (['users' => 'id', 'member_profiles' => 'user_id', 'memberships' => 'user_id', 'user_roles' => 'user_id', 'policy_acknowledgements' => 'user_id', 'notification_deliveries' => 'user_id', 'email_change_requests' => 'user_id', 'password_reset_tokens' => 'user_id'] as $table => $column) {
        $rows = array_map(fn ($row) => serialize((array) $row), DB::table($table)->whereIn($column, $ids)->get()->all());
        sort($rows, SORT_STRING);
        $result[$table] = hash('sha256', serialize($rows));
    }
    $memberships = DB::table('memberships')->whereIn('user_id', $ids)->pluck('id');
    $result['credentials'] = hash('sha256', serialize(DB::table('member_credentials')->whereIn('membership_id', $memberships)->orderBy('id')->get()->all()));

    return $result;
}

try {
    app(LocalMailScope::class)->assertReady();
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $run = $input['run'] ?? '';
    if (! is_string($run) || ! preg_match('/\A[a-f0-9]{32}\z/', $run)) {
        throw new RuntimeException('Invalid run.');
    }
    $directory = storage_path('app/private/local-background-smoke');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $manifestPath = $directory.'/'.$run.'.json';
    $operation = $input['operation'] ?? '';
    if ($operation === 'baseline') {
        if (file_exists($manifestPath)) {
            throw new RuntimeException('Run already exists.');
        }
        $ids = User::query()->pluck('id')->all();
        file_put_contents($manifestPath, json_encode(['ids' => $ids, 'snapshot' => existingSnapshot($ids)], JSON_THROW_ON_ERROR));
        echo json_encode(['existing_users' => count($ids)]);
    } elseif ($operation === 'gap') {
        $email = 'background-'.$run.'-gap@example.test';
        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException('Synthetic gap account already exists.');
        }
        // Only this short-lived helper omits the enqueue callback; no global configuration/job is edited.
        config(['sorerunapp.outbox.dispatch_on_commit' => false]);
        app(RegisterMember::class)->handle(new RegisterMemberData('Anggota Uji Background', $email, $input['password'],
            '2000-01-01', '+6281234567890', 'Kota Uji', null,
            config('sorerunapp.registration.privacy_notice_version'), config('sorerunapp.registration.terms_version')), (string) Str::uuid());
        echo json_encode(['created_without_enqueue' => true, 'queue_jobs' => DB::table('jobs')->where('queue', LocalMailScope::QUEUE)->count()]);
    } elseif ($operation === 'inspect') {
        $users = User::query()->whereIn('email', array_map(fn ($suffix) => 'background-'.$run.'-'.$suffix.'@example.test', ['normal', 'paused', 'gap']))->get();
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        echo json_encode(['accounts' => $users->map(fn ($user) => ['id' => $user->id, 'email' => $user->email, 'verified' => $user->email_verified_at !== null, 'member_number' => $user->membership?->member_number]),
            'deliveries' => DB::table('notification_deliveries')->whereIn('user_id', $users->pluck('id'))->orderBy('id')->get(['id', 'user_id', 'type', 'status', 'attempt_count', 'last_error_code']),
            'queue_jobs' => DB::table('jobs')->where('queue', LocalMailScope::QUEUE)->count(),
            'existing_unchanged' => existingSnapshot($manifest['ids']) === $manifest['snapshot'],
        ], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unsupported operation.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Guarded local smoke helper failed: '.$error::class);
    exit(1);
}
