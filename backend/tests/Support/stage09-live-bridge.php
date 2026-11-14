<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/stage09-live-environment.php';
try {
    $app = bootStage09Live();
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\A[a-f0-9]{32}\z/D', (string) ($input['run'] ?? ''))) {
        throw new RuntimeException('Invalid synthetic run.');
    }
    $old = 'stage09-live-'.$input['run'].'@example.test';
    $new = 'stage09-new-'.$input['run'].'@example.test';
    $operation = $input['operation'] ?? '';
    if ($operation === 'create') {
        (new CoreRolesSeeder)->run();
        DB::transaction(function () use ($old): void {
            $user = User::factory()->create(['email' => $old, 'password' => 'Stage09-live-password-123', 'email_verified_at' => now('UTC')]);
            $user->memberProfile()->create(['full_name' => 'Anggota Integrasi Lokal', 'birth_date' => '2000-01-01', 'phone' => '+6281234567890', 'city' => 'Kota Uji']);
            $user->membership()->save(new Membership);
            $user->roles()->attach(Role::query()->where('code', 'member')->value('id'), ['assigned_by' => null, 'assigned_at' => now('UTC')]);
        });
    } elseif ($operation === 'deliver') {
        $status = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'mail', '--stop-when-empty' => true, '--max-jobs' => 100, '--tries' => 1, '--timeout' => 60, '--sleep' => 0]);
        if ($status !== 0) {
            throw new RuntimeException('Isolated queue worker failed.');
        }
        $user = User::query()->whereIn('email', [$old, $new])->sole();
        $delivery = DB::table('notification_deliveries')->where('user_id', $user->id)->where('type', 'email_change')->latest('id')->first(['status', 'last_error_code']);
        echo json_encode(['delivery' => $delivery], JSON_THROW_ON_ERROR);
        exit(0);
    } elseif ($operation === 'inspect') {
        $user = User::query()->whereIn('email', [$old, $new])->sole();
        echo json_encode(['email' => $user->email, 'version' => $user->auth_version, 'verified' => $user->email_verified_at !== null,
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'consumed' => DB::table('email_change_requests')->where('user_id', $user->id)->whereNotNull('consumed_at')->count()], JSON_THROW_ON_ERROR);
        exit(0);
    } elseif ($operation === 'cleanup') {
        DB::transaction(function () use ($old, $new): void {
            $ids = User::query()->whereIn('email', [$old, $new])->pluck('id');
            DB::table('audit_logs')->where('target_type', 'user')->whereIn('target_id', $ids)->delete();
            foreach (['sessions', 'notification_deliveries', 'user_roles', 'member_profiles', 'memberships'] as $table) {
                DB::table($table)->whereIn('user_id', $ids)->delete();
            }
            User::query()->whereKey($ids)->delete();
        });
    } else {
        throw new RuntimeException('Unsupported synthetic operation.');
    }
    echo '{"ok":true}';
} catch (Throwable $error) {
    fwrite(STDERR, 'Stage09 isolated helper failed: '.$error::class);
    exit(1);
}
