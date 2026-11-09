<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\VerifyMemberEmailController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ValidateEmailVerificationSignature;
use App\Http\Middleware\ValidatePublicVerificationJson;
use Illuminate\Support\Facades\Route;

// Web middleware owns the guest CSRF session. GET never activates a member.
Route::get('/api/v1/auth/email-verification/{id}/{hash}', VerifyMemberEmailController::class)
    ->whereNumber('id')->where('hash', '[a-f0-9]{40}')
    ->middleware([AssignRequestId::class, ValidateEmailVerificationSignature::class])
    ->name('api.v1.auth.email-verification.verify');

Route::post('/api/v1/auth/email-verification/{id}/{hash}', VerifyMemberEmailController::class)
    ->whereNumber('id')->where('hash', '[a-f0-9]{40}')
    ->middleware([AssignRequestId::class, ValidateEmailVerificationSignature::class, ValidatePublicVerificationJson::class])
    ->name('api.v1.auth.email-verification.confirm');
