<?php

namespace App\Providers;

use App\Models\MemberProfile;
use App\Policies\MemberProfilePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        config(['mail.mailers.smtp.timeout' => 15]);
        Gate::policy(MemberProfile::class, MemberProfilePolicy::class);

        RateLimiter::for('verification-resend', function (Request $request): array {
            return [
                Limit::perHour(5)->by('verification:user:'.hash('sha256', (string) $request->user()?->id)),
                Limit::perHour(10)->by('verification:ip:'.hash('sha256', (string) $request->ip())),
            ];
        });

        RateLimiter::for('registration', function (Request $request): array {
            $ipHash = hash('sha256', (string) $request->ip());
            $email = $request->input('email');
            $emailKey = is_string($email) && trim($email) !== ''
                ? hash('sha256', mb_strtolower(trim($email), 'UTF-8'))
                : 'invalid-'.$ipHash;

            return [
                Limit::perMinute(5)->by('registration:ip:'.$ipHash),
                Limit::perHour(3)->by('registration:email:'.$emailKey),
            ];
        });

        RateLimiter::for('login', function (Request $request): array {
            $ipHash = hash('sha256', (string) $request->ip());
            $email = $request->input('email');
            $emailKey = is_string($email) && trim($email) !== ''
                ? hash('sha256', mb_strtolower(trim($email), 'UTF-8'))
                : 'invalid-'.$ipHash;

            return [
                Limit::perMinute(20)->by('login:ip:'.$ipHash),
                Limit::perMinute(5)->by('login:email:'.$emailKey),
            ];
        });
    }
}
