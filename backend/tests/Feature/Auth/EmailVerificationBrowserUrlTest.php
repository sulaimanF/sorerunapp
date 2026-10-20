<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Services\Auth\EmailVerificationUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmailVerificationBrowserUrlTest extends TestCase
{
    private function user(): User
    {
        return (new User)->forceFill(['id' => 41, 'email' => 'browser-link@example.test', 'auth_version' => 3]);
    }

    public function test_browser_fragment_wraps_an_unchanged_valid_authenticated_api_link(): void
    {
        config(['app.url' => 'http://localhost:8000', 'sorerunapp.frontend_url' => 'http://localhost:3000']);
        $url = app(EmailVerificationUrl::class)->forBrowser($this->user());
        self::assertSame('http', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('localhost', parse_url($url, PHP_URL_HOST));
        self::assertSame(3000, parse_url($url, PHP_URL_PORT));
        self::assertSame('/verify-email', parse_url($url, PHP_URL_PATH));
        self::assertNull(parse_url($url, PHP_URL_QUERY));
        parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $fragment);
        self::assertSame(['verification'], array_keys($fragment));
        $request = Request::create($fragment['verification']);
        self::assertTrue(URL::hasValidSignature($request));
        self::assertSame('http://localhost:8000', $request->getSchemeAndHttpHost());
        self::assertSame('3', $request->query('v'));
        self::assertStringContainsString(sha1($this->user()->email), $request->path());
        self::assertGreaterThan(time() + 3500, (int) $request->query('expires'));
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_frontend_origin_is_not_used_in_email(string $origin): void
    {
        config(['sorerunapp.frontend_url' => $origin]);
        $this->expectException(ApiProblem::class);
        app(EmailVerificationUrl::class)->forBrowser($this->user());
    }

    public static function invalidOrigins(): array
    {
        return [[''], ['javascript:alert(1)'], ['ftp://example.test'], ['https://user:pass@example.test'], ['https://example.test/other'], ['https://example.test/?next=bad'], ['https://example.test/#fragment']];
    }
}
