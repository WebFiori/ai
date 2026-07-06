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
use WebFiori\Ai\Http\CurlHttpClient;

/**
 * Unit tests for the CurlHttpClient class.
 *
 * @author Ibrahim
 */
class CurlHttpClientTest extends TestCase {
    /**
     * @test
     */
    public function testConnectTimeoutSetter() {
        $client = new CurlHttpClient();
        $client->setConnectTimeout(30);

        $this->assertEquals(30, $client->getConnectTimeout());
    }

    /**
     * @test
     */
    public function testDefaultConfiguration() {
        $client = new CurlHttpClient();

        $this->assertEquals(120, $client->getTimeout());
        $this->assertEquals(10, $client->getConnectTimeout());
        $this->assertTrue($client->getVerifySsl());
    }

    /**
     * @test
     */
    public function testCustomConfiguration() {
        $client = new CurlHttpClient(60, 5, false);

        $this->assertEquals(60, $client->getTimeout());
        $this->assertEquals(5, $client->getConnectTimeout());
        $this->assertFalse($client->getVerifySsl());
    }

    /**
     * @test
     */
    public function testMethodChaining() {
        $client = new CurlHttpClient();
        $result = $client->setTimeout(60)->setConnectTimeout(5)->setVerifySsl(false);

        $this->assertSame($client, $result);
        $this->assertEquals(60, $client->getTimeout());
        $this->assertEquals(5, $client->getConnectTimeout());
        $this->assertFalse($client->getVerifySsl());
    }

    /**
     * @test
     */
    public function testTimeoutSetter() {
        $client = new CurlHttpClient();
        $client->setTimeout(60);

        $this->assertEquals(60, $client->getTimeout());
    }

    /**
     * @test
     */
    public function testVerifySslSetter() {
        $client = new CurlHttpClient();
        $client->setVerifySsl(false);

        $this->assertFalse($client->getVerifySsl());
    }
}
