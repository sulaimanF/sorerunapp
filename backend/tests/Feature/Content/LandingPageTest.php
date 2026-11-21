<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Actions\Content\DeleteLandingMedia;
use App\Actions\Content\PublishLandingPage;
use App\Actions\Content\SaveLandingDraft;
use App\Exceptions\ApiProblem;
use App\Models\AuditLog;
use App\Models\LandingPage;
use App\Models\LandingPageMedia;
use App\Models\LandingPageRevision;
use App\Models\Role;
use App\Services\Content\LandingDocument;
use App\Services\Content\StoreLandingImage;
use Database\Seeders\CoreRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\EnforceCsrfInRegistrationTests;
use Tests\Support\LandingFixtures;
use Tests\Support\VerificationBrowser;
use Tests\TestCase;

final class LandingPageTest extends TestCase
{
    use LandingFixtures, VerificationBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireLandingDatabase();
        DB::beginTransaction();
        $this->seed(CoreRolesSeeder::class);
        if (LandingPage::query()->findOrFail(1)->draft_version !== 0 || LandingPageRevision::query()->exists()) {
            throw new RuntimeException('Landing tests require the initial isolated landing singleton.');
        }
        Storage::fake('landing');
        Cache::store('array')->flush();
        config(['app.url' => 'http://localhost:8000', 'sanctum.stateful' => ['localhost:3000', 'localhost:8000'], 'sanctum.middleware.validate_csrf_token' => EnforceCsrfInRegistrationTests::class]);
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_empty_publication_is_distinct_from_missing_schema_and_contains_no_draft(): void
    {
        $response = $this->getJson('/api/v1/public/landing-page')->assertOk()->assertJsonPath('data.publication', null);
        self::assertSame(['publication'], array_keys($response->json('data')));
        self::assertTrue(Str::isUuid($response->json('meta.request_id')));
    }

    public function test_guest_image_and_html_requests_receive_401_instead_of_a_backend_login_redirect(): void
    {
        foreach (['image/webp,image/*,*/*;q=0.8', 'text/html', '*/*'] as $accept) {
            $this->get('/api/v1/admin/landing-page/media/'.Str::uuid(), ['Accept' => $accept])
                ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertJsonStructure(['meta' => ['request_id']]);
        }
    }

    public function test_public_rate_limit_returns_retry_after(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/public/landing-page')->assertOk();
        }
        $this->getJson('/api/v1/public/landing-page')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_admin_without_membership_can_save_preview_and_publish_with_csrf(): void
    {
        $user = $this->contentAdmin();
        self::assertNull($user->membership);
        $this->csrf();
        $this->login($user)->assertOk();
        $content = $this->publishableContent();
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', ['version' => 0, 'content' => $content], false)->assertStatus(419);
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', ['version' => 0, 'content' => $content])->assertOk()->assertJsonPath('data.version', 1);
        $this->requestJson('GET', '/api/v1/admin/landing-page/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/api/v1/public/landing-page')->assertJsonPath('data.publication', null);
        $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => 1])->assertOk();
        $response = $this->getJson('/api/v1/public/landing-page')->assertJsonPath('data.publication.content.hero.title', 'Headline sintetis');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_guest_member_and_staff_cannot_read_admin_drafts_or_preview(): void
    {
        $this->requestJson('GET', '/api/v1/admin/landing-page')->assertUnauthorized();
        foreach (['member', 'staff'] as $role) {
            $user = $this->contentAdmin();
            $user->roles()->sync([Role::query()->where('code', $role)->value('id') => ['assigned_by' => null, 'assigned_at' => now('UTC')]]);
            $this->csrf();
            $this->login($user)->assertOk();
            $this->requestJson('GET', '/api/v1/admin/landing-page')->assertForbidden();
            $this->requestJson('GET', '/api/v1/admin/landing-page/preview')->assertForbidden();
            $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => 0])->assertForbidden();
        }
    }

    public function test_conflict_does_not_overwrite_and_republishing_is_idempotent(): void
    {
        $user = $this->contentAdmin();
        $content = $this->publishableContent();
        app(SaveLandingDraft::class)->handle($user->id, 1, 0, $content, (string) Str::uuid());
        try {
            app(SaveLandingDraft::class)->handle($user->id, 1, 0, [...$content, 'footer' => ['text' => 'Tidak boleh tersimpan']], (string) Str::uuid());
            self::fail('Expected a version conflict.');
        } catch (ApiProblem $error) {
            self::assertSame('CONTENT_VERSION_CONFLICT', $error->errorCode);
        }
        $a = app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
        $b = app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
        self::assertSame($a->id, $b->id);
        self::assertSame(1, AuditLog::query()->where('action', 'content.published')->count());
        self::assertSame('', LandingPage::query()->findOrFail(1)->draft['footer']['text']);
    }

    public function test_draft_and_hidden_sections_never_replace_a_published_snapshot(): void
    {
        $user = $this->contentAdmin();
        $content = $this->publishableContent();
        $content['about'] = ['title' => 'Draft rahasia', 'description' => 'Konten tersembunyi', 'items' => []];
        $content['sections'][0]['visible'] = false;
        app(SaveLandingDraft::class)->handle($user->id, 1, 0, $content, (string) Str::uuid());
        $revision = app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
        $content['hero']['title'] = 'Draft berikutnya';
        app(SaveLandingDraft::class)->handle($user->id, 1, 1, $content, (string) Str::uuid());
        $response = $this->getJson('/api/v1/public/landing-page')->assertOk();
        self::assertStringNotContainsString('rahasia', $response->getContent());
        self::assertStringNotContainsString('Draft berikutnya', $response->getContent());
        self::assertSame('Headline sintetis', $revision->fresh()->content['hero']['title']);
    }

    public function test_publish_audit_failure_rolls_back_pointer_snapshot_and_relations(): void
    {
        $user = $this->contentAdmin();
        $media = $this->fakeMedia($user->id);
        $content = $this->publishableContent();
        $content['hero']['image'] = ['media_id' => $media->public_id, 'alt' => 'Gambar rollback', 'caption' => ''];
        app(SaveLandingDraft::class)->handle($user->id, 1, 0, $content, (string) Str::uuid());
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'content.published') {
                throw new RuntimeException('SYNTHETIC_AUDIT_FAILURE');
            }
        });
        try {
            try {
                app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
                self::fail('Expected rollback.');
            } catch (RuntimeException $error) {
                self::assertSame('SYNTHETIC_AUDIT_FAILURE', $error->getMessage());
            }
            self::assertNull(LandingPage::query()->findOrFail(1)->published_revision_id);
            self::assertSame(0, LandingPageRevision::query()->count());
            self::assertSame(0, DB::table('landing_page_revision_media')->count());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_published_snapshot_cannot_be_updated_through_the_model(): void
    {
        $user = $this->contentAdmin();
        app(SaveLandingDraft::class)->handle($user->id, 1, 0, $this->publishableContent(), (string) Str::uuid());
        $revision = app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
        $changed = $revision->content;
        $changed['hero']['title'] = 'Perubahan terlarang';
        try {
            $revision->forceFill(['content' => $changed])->save();
            self::fail('Expected immutable snapshot.');
        } catch (\LogicException) {
            self::assertSame('Headline sintetis', $revision->fresh()->content['hero']['title']);
        }
    }

    public function test_markup_external_image_urls_and_javascript_links_are_rejected(): void
    {
        $documents = app(LandingDocument::class);
        foreach (['markup', 'link', 'field'] as $case) {
            $content = $this->publishableContent();
            if ($case === 'markup') {
                $content['hero']['title'] = '<script>alert(1)</script>';
            }
            if ($case === 'link') {
                $content['contact']['socials'] = [['label' => 'Uji', 'url' => 'javascript:alert(1)']];
            }
            if ($case === 'field') {
                $content['hero']['login_url'] = 'https://external.example';
            }
            try {
                $documents->validate($content);
                self::fail('Expected validation failure.');
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors());
            }
        }
    }

    public function test_stale_version_unverified_blocked_and_revoked_admin_are_rechecked_in_action(): void
    {
        foreach (['stale', 'unverified', 'blocked', 'revoked'] as $case) {
            $user = $this->contentAdmin();
            if ($case === 'stale') {
                $user->forceFill(['auth_version' => 2])->save();
            }
            if ($case === 'unverified') {
                $user->forceFill(['email_verified_at' => null])->save();
            }
            if ($case === 'blocked') {
                $user->forceFill(['status' => 'blocked'])->save();
            }
            if ($case === 'revoked') {
                $user->roles()->detach();
            }
            try {
                app(SaveLandingDraft::class)->handle($user->id, 1, 0, $this->publishableContent(), (string) Str::uuid());
                self::fail('Expected rejection.');
            } catch (ApiProblem|AuthorizationException) {
                self::assertSame(0, LandingPage::query()->findOrFail(1)->draft_version);
            }
        }
    }

    public function test_media_is_private_until_publish_and_references_block_deletion(): void
    {
        $user = $this->contentAdmin();
        $media = $this->fakeMedia($user->id);
        $path = '/api/v1/public/landing-page/media/'.$media->public_id;
        $this->get($path)->assertNotFound();
        $this->requestJson('GET', '/api/v1/admin/landing-page/media/'.$media->public_id)->assertUnauthorized();
        $content = $this->publishableContent();
        $content['hero']['image'] = ['media_id' => $media->public_id, 'alt' => 'Gambar sintetis', 'caption' => ''];
        app(SaveLandingDraft::class)->handle($user->id, 1, 0, $content, (string) Str::uuid());
        try {
            app(DeleteLandingMedia::class)->handle($user->id, 1, strtoupper($media->public_id), (string) Str::uuid());
            self::fail('Expected media in use.');
        } catch (ApiProblem $error) {
            self::assertSame('MEDIA_IN_USE', $error->errorCode);
        }
        app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $content['hero']['image'] = null;
        app(SaveLandingDraft::class)->handle($user->id, 1, 1, $content, (string) Str::uuid());
        try {
            app(DeleteLandingMedia::class)->handle($user->id, 1, $media->public_id, (string) Str::uuid());
            self::fail('Expected retained revision protection.');
        } catch (ApiProblem $error) {
            self::assertSame('MEDIA_IN_USE', $error->errorCode);
        }
    }

    public function test_upload_reencodes_and_audit_failure_removes_only_its_new_file(): void
    {
        $user = $this->contentAdmin();
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('GD WebP is required for upload verification.');
        }
        $file = UploadedFile::fake()->image('synthetic.png', 100, 100);
        $media = app(StoreLandingImage::class)->handle($user->id, 1, $file, (string) Str::uuid());
        self::assertSame('image/webp', $media->mime);
        Storage::disk('landing')->assertExists($media->path);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'content.media_uploaded') {
                throw new RuntimeException('SYNTHETIC_UPLOAD_FAILURE');
            }
        });
        try {
            try {
                app(StoreLandingImage::class)->handle($user->id, 1, $file, (string) Str::uuid());
                self::fail('Expected upload rollback.');
            } catch (RuntimeException $error) {
                self::assertSame('SYNTHETIC_UPLOAD_FAILURE', $error->getMessage());
            }
            self::assertCount(1, Storage::disk('landing')->allFiles('images'));
            self::assertSame(1, LandingPageMedia::query()->count());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_failed_file_delete_keeps_a_durable_tombstone_and_retry_completes(): void
    {
        $user = $this->contentAdmin();
        $media = $this->fakeMedia($user->id);
        $manager = Storage::getFacadeRoot();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with($media->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('landing')->andReturn($disk);
        try {
            try {
                app(DeleteLandingMedia::class)->handle($user->id, 1, $media->public_id, (string) Str::uuid());
                self::fail('Expected pending deletion.');
            } catch (ApiProblem $error) {
                self::assertSame('MEDIA_DELETE_PENDING', $error->errorCode);
            }
            self::assertSame('deleting', $media->fresh()->state);
        } finally {
            Storage::swap($manager);
        }
        app(DeleteLandingMedia::class)->handle($user->id, 1, $media->public_id, (string) Str::uuid());
        self::assertNull($media->fresh());
        Storage::disk('landing')->assertMissing($media->path);
    }

    public function test_incomplete_draft_saves_but_cannot_publish(): void
    {
        $user = $this->contentAdmin();
        app(SaveLandingDraft::class)->handle($user->id, 1, 0, app(LandingDocument::class)->empty(), (string) Str::uuid());
        try {
            app(PublishLandingPage::class)->handle($user->id, 1, 1, (string) Str::uuid());
            self::fail('Expected incomplete content.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('content.hero.title', $error->errors());
        }
        self::assertNull(LandingPage::query()->findOrFail(1)->published_revision_id);
        self::assertSame(0, LandingPageRevision::query()->count());
    }

    public function test_svg_and_excess_pixels_are_rejected_without_writes(): void
    {
        $user = $this->contentAdmin();
        $file = UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>');
        try {
            app(StoreLandingImage::class)->handle($user->id, 1, $file, (string) Str::uuid());
            self::fail('Expected invalid image.');
        } catch (ValidationException) {
            self::assertSame(0, LandingPageMedia::query()->count());
        }
        $wide = UploadedFile::fake()->image('too-wide.png', 6001, 16);
        try {
            app(StoreLandingImage::class)->handle($user->id, 1, $wide, (string) Str::uuid());
            self::fail('Expected excessive width.');
        } catch (ValidationException) {
            self::assertSame(0, LandingPageMedia::query()->count());
        }
        $large = UploadedFile::fake()->image('too-large.png', 100, 100)->size(5121);
        try {
            app(StoreLandingImage::class)->handle($user->id, 1, $large, (string) Str::uuid());
            self::fail('Expected excessive size.');
        } catch (ValidationException) {
            self::assertSame(0, LandingPageMedia::query()->count());
        }
    }

    public function test_http_admin_session_is_rejected_after_block_verification_or_version_changes(): void
    {
        foreach (['blocked', 'unverified', 'stale'] as $case) {
            $user = $this->contentAdmin();
            $this->csrf();
            $this->login($user)->assertOk();
            $changes = match ($case) {
                'blocked' => ['status' => 'blocked'],
                'unverified' => ['email_verified_at' => null],
                'stale' => ['auth_version' => 2],
            };
            $user->forceFill($changes)->save();
            $response = $this->requestJson('GET', '/api/v1/admin/landing-page/preview');
            self::assertContains($response->status(), [401, 403]);
            self::assertSame(0, LandingPage::findOrFail(1)->draft_version);
        }
    }

    public function test_http_rejects_invalid_versions_unknown_fields_and_non_object_json(): void
    {
        $user = $this->contentAdmin();
        $this->csrf();
        $this->login($user)->assertOk();
        foreach ([-1, 1.5, 4294967295, 'wrong'] as $version) {
            $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', ['version' => $version, 'content' => $this->publishableContent()])->assertUnprocessable();
        }
        $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => 0, 'actor_id' => $user->id])->assertUnprocessable();
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', rawBody: '[]')->assertStatus(400);
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', rawBody: '{')->assertStatus(400);
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', rawBody: str_repeat(' ', 131073))->assertStatus(413);
        $this->requestJson('PUT', '/api/v1/admin/landing-page/draft', extraHeaders: ['Content-Type' => 'text/plain'], rawBody: '{}')->assertStatus(415);
        self::assertSame(0, LandingPage::findOrFail(1)->draft_version);
    }

    public function test_admin_publish_rate_limit_and_csrf_are_enforced_without_writes(): void
    {
        $user = $this->contentAdmin();
        $this->csrf();
        $this->login($user)->assertOk();
        $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => 0], false)->assertStatus(419);
        for ($i = 0; $i < 10; $i++) {
            $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => 0])->assertUnprocessable();
        }
        $this->requestJson('POST', '/api/v1/admin/landing-page/publish', ['version' => 0])->assertStatus(429)->assertHeader('Retry-After');
        self::assertSame(0, LandingPageRevision::query()->count());
    }

    public function test_real_image_formats_are_reencoded_with_new_paths_and_without_metadata(): void
    {
        $user = $this->contentAdmin();
        $paths = [];
        foreach (['jpeg', 'png', 'webp'] as $extension) {
            $file = UploadedFile::fake()->image('synthetic.'.$extension, 2500, 32);
            if ($extension === 'jpeg') {
                $bytes = $file->getContent();
                $comment = 'SYNTHETIC_PRIVATE_METADATA';
                $file = UploadedFile::fake()->createWithContent('synthetic.jpeg', substr($bytes, 0, 2)."\xff\xfe".pack('n', strlen($comment) + 2).$comment.substr($bytes, 2));
            }
            $media = app(StoreLandingImage::class)->handle($user->id, 1, $file, (string) Str::uuid());
            $bytes = Storage::disk('landing')->get($media->path);
            $info = getimagesizefromstring($bytes);
            self::assertSame('image/webp', $info['mime']);
            self::assertSame(2400, $info[0]);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_METADATA', $bytes);
            self::assertStringNotContainsString('EXIF', $bytes);
            self::assertNotContains($media->path, $paths);
            $paths[] = $media->path;
        }
        self::assertCount(3, $paths);
    }

    public function test_corrupt_spoofed_and_excess_pixel_images_never_write_files(): void
    {
        $user = $this->contentAdmin();
        $files = [
            UploadedFile::fake()->createWithContent('pretend.png', '<?php echo "unsafe";'),
            UploadedFile::fake()->createWithContent('corrupt.webp', 'RIFFbrokenWEBP'),
            UploadedFile::fake()->image('pixels.png', 4100, 4000),
        ];
        foreach ($files as $file) {
            try {
                app(StoreLandingImage::class)->handle($user->id, 1, $file, (string) Str::uuid());
                self::fail('Expected invalid content or pixel count.');
            } catch (ValidationException) {
                self::assertSame(0, LandingPageMedia::query()->count());
            }
        }
        self::assertSame([], Storage::disk('landing')->allFiles());
    }

    public function test_missing_or_deleting_media_and_missing_image_keys_are_rejected(): void
    {
        $user = $this->contentAdmin();
        $media = $this->fakeMedia($user->id);
        $media->forceFill(['state' => 'deleting'])->save();
        foreach ([$media->public_id, (string) Str::uuid()] as $id) {
            $content = $this->publishableContent();
            $content['hero']['image'] = ['media_id' => $id, 'alt' => 'Gambar uji', 'caption' => ''];
            try {
                app(SaveLandingDraft::class)->handle($user->id, 1, 0, $content, (string) Str::uuid());
                self::fail('Expected unavailable media.');
            } catch (ValidationException) {
                self::assertSame(0, LandingPage::findOrFail(1)->draft_version);
            }
        }
        $content['hero']['image'] = ['media_id' => $media->public_id, 'alt' => ''];
        $this->expectException(ValidationException::class);
        app(LandingDocument::class)->validate($content);
    }

    public function test_storage_write_failure_cannot_report_upload_success_or_attach_metadata(): void
    {
        $user = $this->contentAdmin();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('landing')->andReturn($disk);
        try {
            app(StoreLandingImage::class)->handle($user->id, 1, UploadedFile::fake()->image('test.png', 32, 32), (string) Str::uuid());
            self::fail('Expected storage failure.');
        } catch (ApiProblem $error) {
            self::assertSame('MEDIA_STORAGE_UNAVAILABLE', $error->errorCode);
            self::assertSame(0, LandingPageMedia::query()->count());
            self::assertSame(0, AuditLog::query()->where('action', 'content.media_uploaded')->count());
        }
    }

    public function test_failed_orphan_cleanup_retains_the_new_file_and_logs_only_a_random_reference(): void
    {
        $user = $this->contentAdmin();
        $realDisk = Storage::disk('landing');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnUsing(fn ($path, $bytes) => $realDisk->put($path, $bytes));
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('landing')->andReturn($disk);
        Log::spy();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'content.media_uploaded') {
                throw new RuntimeException('SYNTHETIC_ORPHAN_FAILURE');
            }
        });
        try {
            try {
                app(StoreLandingImage::class)->handle($user->id, 1, UploadedFile::fake()->image('test.png', 32, 32), (string) Str::uuid());
                self::fail('Expected upload rollback.');
            } catch (RuntimeException $error) {
                self::assertSame('SYNTHETIC_ORPHAN_FAILURE', $error->getMessage());
            }
            self::assertSame(0, LandingPageMedia::query()->count());
            self::assertCount(1, $realDisk->allFiles('images'));
            Log::shouldHaveReceived('warning')->once()->with('Landing media orphan cleanup required.', \Mockery::on(fn ($context) => array_keys($context) === ['media_reference'] && Str::isUuid($context['media_reference'])));
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_http_media_upload_uses_csrf_and_never_uses_the_client_filename_as_path(): void
    {
        $user = $this->contentAdmin();
        $this->csrf();
        $this->login($user)->assertOk();
        $upload = function (bool $csrf) {
            Auth::forgetGuards();
            $this->app->forgetInstance('auth.driver');
            Auth::shouldUse('web');
            app('session')->forgetDrivers();
            $this->app->forgetInstance('session.store');
            $this->flushHeaders();
            $this->unencryptedCookies = [];
            $this->withUnencryptedCookies($this->browserCookies)->withCredentials();
            $headers = ['Origin' => 'http://localhost:3000', 'Accept' => 'application/json'];
            if ($csrf) {
                $headers['X-XSRF-TOKEN'] = $this->browserCookies['XSRF-TOKEN'];
            }

            return $this->call('POST', 'http://localhost:8000/api/v1/admin/landing-page/media', cookies: $this->prepareCookiesForJsonRequest(), files: ['image' => UploadedFile::fake()->image('../../synthetic.png', 32, 32)], server: $this->transformHeadersToServerVars($headers));
        };
        $upload(false)->assertStatus(419);
        $response = $upload(true)->assertCreated();
        self::assertSame(['id', 'mime', 'width', 'height', 'url'], array_keys($response->json('data')));
        $media = LandingPageMedia::query()->sole();
        self::assertSame('images/'.$media->public_id.'.webp', $media->path);
        $this->get('/api/v1/public/landing-page/media/'.$media->public_id)->assertNotFound();
        $this->get('/api/v1/public/landing-page/media/..%2F..%2F.env')->assertNotFound();
    }

    private function fakeMedia(int $actor): LandingPageMedia
    {
        $id = (string) Str::uuid();
        Storage::disk('landing')->put('images/'.$id.'.webp', 'synthetic-image-content');
        $media = (new LandingPageMedia)->forceFill(['public_id' => $id, 'path' => 'images/'.$id.'.webp', 'mime' => 'image/webp', 'size' => 23, 'width' => 100, 'height' => 100, 'state' => 'ready', 'uploaded_by' => $actor, 'created_at' => now('UTC')]);
        $media->save();

        return $media;
    }
}
