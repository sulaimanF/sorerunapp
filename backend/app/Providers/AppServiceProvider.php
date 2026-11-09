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

        // email-first: uniform public limits, no account lookup in the limiter.
        RateLimiter::for('verification-resend', function (Request $request): array {
            $ip = hash('sha256', (string) $request->ip());
            $email = $request->input('email');
            $key = is_string($email) ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : 'invalid-'.$ip;

            return [
                Limit::perMinute(5)->by('verification:minute:'.$ip),
                Limit::perHour(20)->by('verification:ip:'.$ip),
                Limit::perHour(5)->by('verification:email:'.$key),
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
