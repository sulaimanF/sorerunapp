<?php

declare(strict_types=1);

use App\Models\AdminProvisioning;
use App\Models\LandingPageMedia;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdministrationCommittedFixtures;

require __DIR__.'/stage11-environment.php';
bootStage11();
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\A[a-f0-9]{32}\z/D', $input['run'] ?? '')) {
        throw new RuntimeException('Invalid run.');
    }
    $email = 'stage11-live-'.$input['run'].'@example.test';
    $user = User::query()->where('email', $email)->first();
    if (in_array($input['operation'], ['create', 'create-initial'], true)) {
        if ($user !== null || DB::table('security_bootstraps')->whereNotNull('completed_at')->exists()) {
            throw new RuntimeException('Live fixture/fence already occupied.');
        }
        $user = User::query()->create(['name' => 'Super Uji Integrasi', 'email' => $email, 'password' => Hash::make($input['operation'] === 'create-initial' ? bin2hex(random_bytes(48)) : 'Stage11-test-password-123')]);
        if ($input['operation'] === 'create') {
            $user->forceFill(['email_verified_at' => now('UTC')])->save();
            $user->roles()->attach(Role::query()->where('code', 'admin')->value('id'), ['assigned_at' => now('UTC')]);
        }
        echo json_encode(['user_id' => $user->id], JSON_THROW_ON_ERROR);
    } elseif ($input['operation'] === 'inspect-initial') {
        if ($user === null) {
            throw new RuntimeException('Missing initial fixture.');
        }
        echo json_encode([
            'verified' => $user->email_verified_at !== null, 'version' => $user->auth_version,
            'roles' => $user->roles()->pluck('code')->map(fn ($role) => $role->value)->all(),
            'fence_completed' => DB::table('security_bootstraps')->where('name', 'first_super_admin')->value('completed_at') !== null,
            'activation_audits' => DB::table('audit_logs')->where('target_id', $user->id)->where('action', 'security.first_activation_accepted')->count(),
            'bootstrap_audits' => DB::table('audit_logs')->where('target_id', $user->id)->where('action', 'security.super_admin_bootstrapped')->count(),
            'deliveries' => DB::table('notification_deliveries')->where('user_id', $user->id)->pluck('status')->all(),
            'jobs' => DB::table('jobs')->where('queue', 'mail-bootstrap')->count(),
            'member_data' => DB::table('member_profiles')->where('user_id', $user->id)->count() + DB::table('memberships')->where('user_id', $user->id)->count(),
            'sequence' => DB::table('member_number_sequences')->where('name', 'membership')->value('next_value'),
        ], JSON_THROW_ON_ERROR);
    } elseif ($input['operation'] === 'inspect') {
        if ($user === null) {
            throw new RuntimeException('Missing fixture.');
        }
        $target = AdminProvisioning::query()->where('created_by', $user->id)->first();
        $targetId = $target?->user_id;
        echo json_encode([
            'bootstrap_audits' => DB::table('audit_logs')->where('action', 'security.super_admin_bootstrapped')->where('target_id', $user->id)->count(),
            'target_id' => $targetId, 'activated' => $target?->activated_at !== null,
            'jobs' => DB::table('jobs')->where('queue', 'mail')->count(),
            'target_deliveries' => $targetId === null ? [] : DB::table('notification_deliveries')->where('user_id', $targetId)->pluck('status')->all(),
            'memberships' => $targetId === null ? 0 : DB::table('memberships')->whereIn('user_id', [$user->id, $targetId])->count(),
            'profiles' => $targetId === null ? 0 : DB::table('member_profiles')->whereIn('user_id', [$user->id, $targetId])->count(),
            'credentials' => DB::table('member_credentials')->count(),
            'sequence' => DB::table('member_number_sequences')->where('name', 'membership')->value('next_value'),
            'target_version' => $targetId === null ? null : User::findOrFail($targetId)->auth_version,
        ], JSON_THROW_ON_ERROR);
    } elseif ($input['operation'] === 'cleanup') {
        if ($user !== null) {
            $actorId = (int) $user->id;
            $page = DB::table('landing_pages')->where('id', 1)->first();
            if ((int) $page->updated_by === $actorId) {
                $revisions = DB::table('landing_page_revisions')->where('published_by', $actorId)->pluck('id');
                DB::table('landing_pages')->where('id', 1)->update(['draft' => null, 'draft_version' => 0, 'published_revision_id' => null, 'updated_by' => null]);
                DB::table('landing_page_revision_media')->whereIn('revision_id', $revisions)->delete();
                DB::table('landing_page_revisions')->whereIn('id', $revisions)->delete();
            }
            foreach (LandingPageMedia::query()->where('uploaded_by', $actorId)->get() as $media) {
                Storage::disk('landing')->delete($media->path);
                $media->delete();
            }
            DB::table('audit_logs')->where('actor_user_id', $actorId)->where('target_type', 'landing_page')->delete();
            (new class
            {
                use AdministrationCommittedFixtures;

                public function clean(int $id): void
                {
                    $this->administrationActors = [$id];
                    $this->cleanupAdministrationFixtures();
                }
            })->clean($actorId);
        }
        echo '{"ok":true}';
    } else {
        throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Stage11 bridge failed: '.get_class($exception));
    exit(1);
}
