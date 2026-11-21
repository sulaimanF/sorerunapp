<?php

use App\Http\Controllers\Api\V1\Administration\AcceptAdminInvitationController;
use App\Http\Controllers\Api\V1\Administration\AcceptFirstAccountActivationController;
use App\Http\Controllers\Api\V1\Administration\AdminAccountController;
use App\Http\Controllers\Api\V1\Administration\ChangeAdministrativePasswordController;
use App\Http\Controllers\Api\V1\Auth\ConfirmEmailChangeController;
use App\Http\Controllers\Api\V1\Auth\CurrentMemberController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginMemberController;
use App\Http\Controllers\Api\V1\Auth\LogoutMemberController;
use App\Http\Controllers\Api\V1\Auth\OwnAccountController;
use App\Http\Controllers\Api\V1\Auth\RegisterMemberController;
use App\Http\Controllers\Api\V1\Auth\RequestEmailChangeController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Content\AdminLandingPageController;
use App\Http\Controllers\Api\V1\Content\LandingMediaController;
use App\Http\Controllers\Api\V1\Content\PublicLandingPageController;
use App\Http\Controllers\Api\V1\Members\ShowMemberProfileController;
use App\Http\Controllers\Api\V1\Members\UpdateMemberProfileController;
use App\Http\Controllers\Api\V1\Memberships\MembershipCardController;
use App\Http\Middleware\EnsureCurrentBrowserSession;
use App\Http\Middleware\RequireBrowserSession;
use App\Http\Middleware\RequireLandingPageAdmin;
use App\Http\Middleware\RequireStatefulRegistration;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\ValidateLandingJson;
use App\Http\Middleware\ValidatePublicVerificationJson;
use Illuminate\Support\Facades\Route;

// Protect the legacy scaffold alias with the same rules and output whitelist.
Route::get('/user', CurrentMemberController::class)
    ->middleware([RequireBrowserSession::class, 'auth:sanctum', EnsureCurrentBrowserSession::class]);

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/first-account-activation/accept', AcceptFirstAccountActivationController::class)
        ->middleware([RequireStatefulRegistration::class, 'throttle:first-account-activation']);
    Route::post('/auth/admin-invitations/accept', AcceptAdminInvitationController::class)
        ->middleware([RequireStatefulRegistration::class, 'throttle:admin-invitation-accept']);
    Route::get('/public/landing-page', PublicLandingPageController::class)->middleware('throttle:landing-public');
    Route::get('/public/landing-page/media/{publicId}', [LandingMediaController::class, 'showPublic'])->whereUuid('publicId')->middleware('throttle:landing-public-media');
    Route::post('/auth/forgot-password', ForgotPasswordController::class)
        ->middleware([RequireStatefulRegistration::class, 'throttle:password-forgot'])->name('api.v1.auth.forgot-password');
    Route::post('/auth/reset-password', ResetPasswordController::class)
        ->middleware([RequireStatefulRegistration::class, 'throttle:password-reset'])->name('api.v1.auth.reset-password');

    Route::post('/auth/register', RegisterMemberController::class)
        ->middleware([RequireStatefulRegistration::class, 'throttle:registration'])
        ->name('api.v1.auth.register');

    // email-first: public resend
    Route::post('/auth/email-verification/resend', ResendVerificationEmailController::class)
        ->middleware([ValidatePublicVerificationJson::class, RequireStatefulRegistration::class, 'throttle:verification-resend'])
        ->name('api.v1.auth.email-verification.resend');

    Route::post('/auth/login', LoginMemberController::class)
        ->middleware([RequireBrowserSession::class, 'throttle:login'])
        ->name('api.v1.auth.login');

    Route::middleware([RequireBrowserSession::class, 'auth:sanctum', EnsureCurrentBrowserSession::class])->group(function (): void {
        Route::prefix('admin/accounts')->middleware(RequireSuperAdmin::class)->group(function (): void {
            Route::get('/', [AdminAccountController::class, 'index'])->middleware('throttle:admin-invitation-read');
            Route::post('/', [AdminAccountController::class, 'store'])->middleware('throttle:admin-provision');
            Route::delete('/{userId}', [AdminAccountController::class, 'destroy'])->whereNumber('userId')->middleware('throttle:admin-account-delete');
            Route::post('/{userId}/password', ChangeAdministrativePasswordController::class)->whereNumber('userId')->middleware('throttle:administrative-password');
            Route::get('/{userId}/invitation', [AdminAccountController::class, 'show'])->whereNumber('userId')->middleware('throttle:admin-invitation-read');
            Route::post('/{userId}/invitation/resend', [AdminAccountController::class, 'resend'])->whereNumber('userId')->middleware('throttle:admin-provision');
        });
        Route::get('/me/account', OwnAccountController::class);
        Route::post('/me/password', ChangeAdministrativePasswordController::class)->middleware('throttle:administrative-password');
        Route::prefix('admin/landing-page')->middleware(RequireLandingPageAdmin::class)->group(function (): void {
            $pages = AdminLandingPageController::class;
            $media = LandingMediaController::class;
            Route::get('/', [$pages, 'show'])->middleware('throttle:landing-admin-read');
            Route::get('/preview', [$pages, 'preview'])->middleware('throttle:landing-admin-read');
            Route::put('/draft', [$pages, 'save'])->middleware([ValidateLandingJson::class, 'throttle:landing-admin-save']);
            Route::post('/publish', [$pages, 'publish'])->middleware([ValidateLandingJson::class, 'throttle:landing-admin-publish']);
            Route::post('/media', [$media, 'upload'])->middleware('throttle:landing-admin-upload');
            Route::get('/media', [$media, 'index'])->middleware('throttle:landing-admin-read');
            Route::get('/media/{publicId}', [$media, 'showAdmin'])->whereUuid('publicId')->middleware('throttle:landing-admin-media');
            Route::delete('/media/{publicId}', [$media, 'delete'])->whereUuid('publicId')->middleware('throttle:landing-admin-delete');
        });
        Route::post('/me/email-change', RequestEmailChangeController::class)->middleware('throttle:email-change')->name('api.v1.me.email-change.request');
        Route::post('/me/email-change/verify', ConfirmEmailChangeController::class)->middleware('throttle:email-change-verify')->name('api.v1.me.email-change.verify');
        Route::get('/me/profile', ShowMemberProfileController::class)->name('api.v1.me.profile.show');
        Route::patch('/me/profile', UpdateMemberProfileController::class)->middleware('throttle:60,1')->name('api.v1.me.profile.update');
        Route::get('/me/membership/card', MembershipCardController::class)->name('api.v1.me.membership.card');
        Route::get('/auth/me', CurrentMemberController::class)->name('api.v1.auth.me');
        Route::post('/auth/logout', LogoutMemberController::class)->name('api.v1.auth.logout');
    });
});
