<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\VerifyMemberEmailController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCurrentBrowserSession;
use App\Http\Middleware\RequireVerificationSession;
use App\Http\Middleware\ValidateEmailVerificationSignature;
use Illuminate\Support\Facades\Route;

// Loaded in the web group, not also in the stateful API group: no double cookie encryption/session start.
Route::get('/api/v1/auth/email-verification/{id}/{hash}', VerifyMemberEmailController::class)
    ->whereNumber('id')->where('hash', '[a-f0-9]{40}')
    ->middleware([AssignRequestId::class, RequireVerificationSession::class, EnsureCurrentBrowserSession::class, ValidateEmailVerificationSignature::class])
    ->name('api.v1.auth.email-verification.verify');
