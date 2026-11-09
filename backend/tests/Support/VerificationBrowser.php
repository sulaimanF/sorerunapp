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

    private function requestJson(string $method, string $path, array $data = [], bool $csrf = true, array $extraHeaders = [], ?string $rawBody = null): TestResponse
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
        if ($rawBody === null) {
            $response = $this->json(
                $method,
                'http://localhost:8000'.$path,
                $data,
                array_replace($headers, $extraHeaders),
                $method === 'POST' && $data === [] ? JSON_FORCE_OBJECT : 0,
            );
        } else {
            // call() needs explicit cookies; withCredentials() alone does not attach them.
            $response = $this->call(
                $method,
                'http://localhost:8000'.$path,
                cookies: $this->prepareCookiesForJsonRequest(),
                server: $this->transformHeadersToServerVars(
                    array_replace($headers, ['Content-Type' => 'application/json'], $extraHeaders),
                ),
                content: $rawBody,
            );
        }
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
