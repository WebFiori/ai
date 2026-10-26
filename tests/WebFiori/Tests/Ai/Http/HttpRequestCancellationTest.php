<?php

namespace WebFiori\Tests\Ai\Http;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\Exception\OperationCancelledException;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpRequest;

/**
 * Tests for request cancellation on the non-streaming HTTP path (issue #178):
 * the HttpRequest token field/wither and CurlHttpClient's progress-callback
 * abort + errno-42 -> OperationCancelledException mapping.
 */
class HttpRequestCancellationTest extends TestCase {
    public function testHttpRequestHasNoTokenByDefault(): void {
        $request = new HttpRequest('POST', 'https://example.com', [], '{}');

        $this->assertNull($request->getCancellationToken());
    }

    public function testHttpRequestCarriesTokenViaConstructor(): void {
        $token = (new CancellationTokenSource())->getToken();
        $request = new HttpRequest('POST', 'https://example.com', [], '{}', $token);

        $this->assertSame($token, $request->getCancellationToken());
    }

    public function testWithCancellationTokenReturnsImmutableCopy(): void {
        $original = new HttpRequest('POST', 'https://example.com', ['X-Test' => '1'], '{"a":1}');
        $token = (new CancellationTokenSource())->getToken();

        $copy = $original->withCancellationToken($token);

        // Original is untouched.
        $this->assertNull($original->getCancellationToken());
        // Copy carries the token and preserves all other fields.
        $this->assertNotSame($original, $copy);
        $this->assertSame($token, $copy->getCancellationToken());
        $this->assertSame('POST', $copy->getMethod());
        $this->assertSame('https://example.com', $copy->getUrl());
        $this->assertSame(['X-Test' => '1'], $copy->getHeaders());
        $this->assertSame('{"a":1}', $copy->getBody());
    }

    public function testWithNullTokenClearsToken(): void {
        $token = (new CancellationTokenSource())->getToken();
        $request = (new HttpRequest('GET', 'https://example.com'))->withCancellationToken($token);

        $cleared = $request->withCancellationToken(null);

        $this->assertNull($cleared->getCancellationToken());
    }

    public function testPreCancelledTokenAbortsSendWithOperationCancelled(): void {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl not available.');
        }

        $source = new CancellationTokenSource();
        $source->cancel(); // already cancelled before sending

        // Unroutable TEST-NET-1 address (RFC 5737) so no real traffic occurs;
        // the progress callback fires during connect and aborts (errno 42).
        $request = new HttpRequest('GET', 'http://192.0.2.1:9', [], null, $source->getToken());
        $client = new CurlHttpClient(2, 2);

        $this->expectException(OperationCancelledException::class);
        $client->send($request);
    }

    public function testNoTokenLeavesBehaviorUnchanged(): void {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl not available.');
        }

        // Without a token, an unreachable host must still surface the normal
        // transport error (HttpException), not OperationCancelledException.
        $request = new HttpRequest('GET', 'http://192.0.2.1:9', [], null);
        $client = new CurlHttpClient(2, 2);

        $this->expectException(\WebFiori\Ai\Exception\HttpException::class);
        $client->send($request);
    }
}
