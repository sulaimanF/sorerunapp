<?php

use App\Http\Controllers\Api\V1\Auth\CurrentMemberController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginMemberController;
use App\Http\Controllers\Api\V1\Auth\LogoutMemberController;
use App\Http\Controllers\Api\V1\Auth\RegisterMemberController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Members\ShowMemberProfileController;
use App\Http\Controllers\Api\V1\Members\UpdateMemberProfileController;
use App\Http\Controllers\Api\V1\Memberships\MembershipCardController;
use App\Http\Middleware\EnsureCurrentBrowserSession;
use App\Http\Middleware\RequireBrowserSession;
use App\Http\Middleware\RequireStatefulRegistration;
use App\Http\Middleware\ValidatePublicVerificationJson;
use Illuminate\Support\Facades\Route;

// Protect the legacy scaffold alias with the same rules and output whitelist.
Route::get('/user', CurrentMemberController::class)
    ->middleware([RequireBrowserSession::class, 'auth:sanctum', EnsureCurrentBrowserSession::class]);

Route::prefix('v1')->group(function (): void {
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
        Route::get('/me/profile', ShowMemberProfileController::class)->name('api.v1.me.profile.show');
        Route::patch('/me/profile', UpdateMemberProfileController::class)->middleware('throttle:60,1')->name('api.v1.me.profile.update');
        Route::get('/me/membership/card', MembershipCardController::class)->name('api.v1.me.membership.card');
        Route::get('/auth/me', CurrentMemberController::class)->name('api.v1.auth.me');
        Route::post('/auth/logout', LogoutMemberController::class)->name('api.v1.auth.logout');
    });
});
