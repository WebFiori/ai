<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;

/**
 * Unit tests for the HTTP value objects.
 *
 * @author Ibrahim
 */
class HttpTest extends TestCase {
    /**
     * @test
     */
    public function testHttpRequestBasic() {
        $request = new HttpRequest('POST', 'https://api.openai.com/v1/chat/completions');

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('https://api.openai.com/v1/chat/completions', $request->getUrl());
        $this->assertEmpty($request->getHeaders());
        $this->assertNull($request->getBody());
    }

    /**
     * @test
     */
    public function testHttpRequestMethodUppercased() {
        $request = new HttpRequest('post', 'https://example.com');

        $this->assertEquals('POST', $request->getMethod());
    }

    /**
     * @test
     */
    public function testHttpRequestWithHeadersAndBody() {
        $headers = [
            'Authorization' => 'Bearer sk-test',
            'Content-Type' => 'application/json',
        ];
        $body = '{"model":"gpt-4o","messages":[]}';
        $request = new HttpRequest('POST', 'https://api.openai.com/v1/chat/completions', $headers, $body);

        $this->assertEquals('Bearer sk-test', $request->getHeader('Authorization'));
        $this->assertEquals('application/json', $request->getHeader('Content-Type'));
        $this->assertNull($request->getHeader('X-Missing'));
        $this->assertEquals($body, $request->getBody());
        $this->assertCount(2, $request->getHeaders());
    }

    /**
     * @test
     */
    public function testHttpResponseBasic() {
        $response = new HttpResponse(200, ['Content-Type' => 'application/json'], '{"result":"ok"}');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('{"result":"ok"}', $response->getBody());
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));
        $this->assertNull($response->getHeader('X-Missing'));
    }

    /**
     * @test
     */
    public function testHttpResponseGetJson() {
        $body = '{"model":"gpt-4o","choices":[]}';
        $response = new HttpResponse(200, [], $body);

        $json = $response->getJson();
        $this->assertEquals('gpt-4o', $json['model']);
        $this->assertIsArray($json['choices']);
    }

    /**
     * @test
     */
    public function testHttpResponseGetJsonInvalid() {
        $response = new HttpResponse(200, [], 'not json');

        $this->expectException(\JsonException::class);
        $response->getJson();
    }

    /**
     * @test
     */
    public function testHttpResponseIsSuccessRanges() {
        $this->assertTrue((new HttpResponse(200))->isSuccess());
        $this->assertTrue((new HttpResponse(201))->isSuccess());
        $this->assertTrue((new HttpResponse(299))->isSuccess());
        $this->assertFalse((new HttpResponse(199))->isSuccess());
        $this->assertFalse((new HttpResponse(300))->isSuccess());
        $this->assertFalse((new HttpResponse(401))->isSuccess());
        $this->assertFalse((new HttpResponse(500))->isSuccess());
    }
}
