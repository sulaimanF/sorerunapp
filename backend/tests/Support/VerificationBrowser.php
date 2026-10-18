<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

trait VerificationBrowser
{
    private array $browserCookies = [];

    private const PASSWORD = 'Test-only-password-123';

    private function csrf(): void
    {
        $this->requestJson('GET', '/sanctum/csrf-cookie')->assertNoContent();
        self::assertArrayHasKey('XSRF-TOKEN', $this->browserCookies);
    }

    private function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->requestJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => $password]);
    }

    private function requestJson(string $method, string $path, array $data = [], bool $csrf = true, array $extraHeaders = []): TestResponse
    {
        // Rebuild per-request services so a cached guard cannot fake cookie authentication.
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        Auth::shouldUse('web');
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->flushHeaders();
        $this->unencryptedCookies = [];
        $this->withUnencryptedCookies($this->browserCookies)->withCredentials();
        $headers = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/', 'Accept' => 'application/json'];
        if ($csrf && isset($this->browserCookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $this->browserCookies['XSRF-TOKEN'];
        }
        $response = $this->json($method, 'http://localhost:8000'.$path, $data, array_replace($headers, $extraHeaders));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() > 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->browserCookies[$cookie->getName()]);
            } else {
                $this->browserCookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
