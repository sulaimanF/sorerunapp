<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageMedia;
use App\Models\LandingPageRevision;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/stage10-environment.php';
try {
    bootStage10(live: true);
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\A[a-f0-9]{32}\z/D', (string) ($input['run'] ?? ''))) {
        throw new RuntimeException('Invalid synthetic run.');
    }
    $email = 'stage10-'.$input['run'].'@example.test';
    if ($input['operation'] === 'create') {
        if (LandingPage::findOrFail(1)->draft_version !== 0 || LandingPageRevision::query()->exists()) {
            throw new RuntimeException('Live test requires an unused isolated landing singleton.');
        }
        (new CoreRolesSeeder)->run();
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now('UTC'), 'password' => 'Stage10-test-password-123']);
        $user->memberProfile()->create(['full_name' => 'Admin Integrasi Sintetis', 'birth_date' => '2000-01-01', 'phone' => '+6281234567890', 'city' => 'Kota Uji']);
        $user->roles()->attach(Role::where('code', 'admin')->value('id'), ['assigned_by' => null, 'assigned_at' => now('UTC')]);
        $image = imagecreatetruecolor(640, 360);
        imagefill($image, 0, 0, imagecolorallocate($image, 251, 185, 49));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        echo json_encode(['image' => base64_encode($bytes)], JSON_THROW_ON_ERROR);
    } elseif ($input['operation'] === 'inspect') {
        $user = User::where('email', $email)->sole();
        echo json_encode(['revisions' => LandingPageRevision::where('published_by', $user->id)->count(),
            'media' => LandingPageMedia::where('uploaded_by', $user->id)->count(),
            'version' => LandingPage::findOrFail(1)->draft_version,
            'batch' => DB::table('migrations')->where('migration', '2026_11_14_120000_create_landing_page_content_tables')->value('batch'),
        ], JSON_THROW_ON_ERROR);
    } elseif ($input['operation'] === 'cleanup') {
        $user = User::where('email', $email)->sole();
        $page = LandingPage::findOrFail(1);
        if ($page->updated_by !== null && $page->updated_by !== $user->id) {
            throw new RuntimeException('Another test owns the draft; refusing cleanup.');
        }
        $revisions = LandingPageRevision::where('published_by', $user->id)->pluck('id');
        if (LandingPageRevision::whereNotIn('id', $revisions)->exists()) {
            throw new RuntimeException('Another test owns a revision; refusing cleanup.');
        }
        $page->forceFill(['draft' => null, 'draft_version' => 0, 'published_revision_id' => null, 'updated_by' => null])->save();
        DB::table('landing_page_revision_media')->whereIn('revision_id', $revisions)->delete();
        DB::table('landing_page_revisions')->whereIn('id', $revisions)->delete();
        foreach (LandingPageMedia::where('uploaded_by', $user->id)->get() as $media) {
            Storage::disk('landing')->delete($media->path);
            $media->delete();
        }
        DB::table('audit_logs')->where('actor_user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('user_roles')->where('user_id', $user->id)->delete();
        $user->memberProfile()->delete();
        $user->delete();
        echo '{"ok":true}';
    } else {
        throw new RuntimeException('Unknown synthetic operation.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated Stage10 helper failed: '.$error::class);
    exit(1);
}
