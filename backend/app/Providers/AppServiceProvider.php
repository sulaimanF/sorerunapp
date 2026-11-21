<?php

namespace App\Providers;

use App\Models\LandingPage;
use App\Models\MemberProfile;
use App\Models\User;
use App\Policies\AdminAccountPolicy;
use App\Policies\LandingPagePolicy;
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
        Gate::policy(User::class, AdminAccountPolicy::class);
        Gate::policy(LandingPage::class, LandingPagePolicy::class);

        RateLimiter::for('admin-invitation-read', fn (Request $request) => Limit::perMinute(60)->by('admin-read:'.$request->user('web')?->id));
        RateLimiter::for('admin-account-delete', fn (Request $request) => Limit::perMinute(10)->by('admin-delete:'.$request->user('web')?->id));
        RateLimiter::for('administrative-password', fn (Request $request) => [
            Limit::perMinute(5)->by('admin-password:minute:'.$request->user('web')?->id),
            Limit::perHour(20)->by('admin-password:hour:'.$request->user('web')?->id),
        ]);
        foreach (['admin-provision', 'admin-invitation-accept', 'first-account-activation'] as $name) {
            RateLimiter::for($name, function (Request $request) use ($name): array {
                $ip = hash('sha256', (string) $request->ip());
                $email = $request->input('email');
                $recipient = is_string($email) ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : 'target-'.hash('sha256', (string) $request->route('userId'));

                return [
                    Limit::perMinute(5)->by($name.':minute:'.$ip),
                    Limit::perHour(20)->by($name.':ip:'.$ip),
                    Limit::perHour(10)->by($name.':actor:'.($request->user('web')?->id ?? $ip)),
                    Limit::perHour(5)->by($name.':recipient:'.$recipient),
                ];
            });
        }

        foreach ([
            'landing-public' => 120, 'landing-public-media' => 240,
            'landing-admin-read' => 60, 'landing-admin-save' => 30,
            'landing-admin-publish' => 10, 'landing-admin-upload' => 10,
            'landing-admin-delete' => 10, 'landing-admin-media' => 240,
        ] as $name => $attempts) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($attempts)->by(hash('sha256', (string) $request->ip())));
        }

        foreach (['email-change', 'email-change-verify'] as $limiter) {
            RateLimiter::for($limiter, function (Request $request) use ($limiter): array {
                $ip = hash('sha256', (string) $request->ip());
                $actor = hash('sha256', (string) $request->user('web')?->id);
                $limits = [
                    Limit::perMinute(5)->by($limiter.':minute:'.$ip),
                    Limit::perHour(20)->by($limiter.':ip:'.$ip),
                    Limit::perHour($limiter === 'email-change' ? 5 : 20)->by($limiter.':actor:'.$actor),
                ];
                if ($limiter === 'email-change') {
                    $email = $request->input('email');
                    $key = is_string($email) ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : 'invalid-'.$ip;
                    $limits[] = Limit::perHour(5)->by($limiter.':email:'.$key);
                }

                return $limits;
            });
        }

        foreach (['password-forgot', 'password-reset'] as $limiter) {
            RateLimiter::for($limiter, function (Request $request) use ($limiter): array {
                $ip = hash('sha256', (string) $request->ip());
                $email = $request->input('email');
                $key = is_string($email) ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : 'invalid-'.$ip;

                return [
                    Limit::perMinute(5)->by($limiter.':minute:'.$ip),
                    Limit::perHour(20)->by($limiter.':ip:'.$ip),
                    Limit::perHour(5)->by($limiter.':email:'.$key),
                ];
            });
        }

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
