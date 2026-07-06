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
use WebFiori\Ai\Exception\AiException;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Exception\HttpException;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Exception\ProviderException;
use WebFiori\Ai\Exception\RateLimitException;
use WebFiori\Ai\Exception\StreamingException;
use WebFiori\Ai\Exception\UnsupportedFeatureException;

/**
 * Unit tests for the exception hierarchy.
 *
 * @author Ibrahim
 */
class ExceptionTest extends TestCase {
    /**
     * @test
     */
    public function testAiExceptionIsBaseForAll() {
        $this->assertInstanceOf(AiException::class, new AuthenticationException('test'));
        $this->assertInstanceOf(AiException::class, new RateLimitException('test'));
        $this->assertInstanceOf(AiException::class, new ProviderException('test'));
        $this->assertInstanceOf(AiException::class, new InvalidConfigException('test'));
        $this->assertInstanceOf(AiException::class, new HttpException('test'));
        $this->assertInstanceOf(AiException::class, new StreamingException('test'));
        $this->assertInstanceOf(AiException::class, new UnsupportedFeatureException('embed', 'test'));
    }

    /**
     * @test
     */
    public function testAiExceptionExtendsRuntimeException() {
        $exception = new AiException('Something went wrong');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Something went wrong', $exception->getMessage());
    }

    /**
     * @test
     */
    public function testAuthenticationException() {
        $exception = new AuthenticationException('Invalid API key', 401);

        $this->assertEquals('Invalid API key', $exception->getMessage());
        $this->assertEquals(401, $exception->getStatusCode());
        $this->assertEquals(401, $exception->getCode());
    }

    /**
     * @test
     */
    public function testAuthenticationExceptionForbidden() {
        $exception = new AuthenticationException('Insufficient permissions', 403);

        $this->assertEquals(403, $exception->getStatusCode());
    }

    /**
     * @test
     */
    public function testAuthenticationExceptionPreviousChaining() {
        $previous = new \RuntimeException('Original error');
        $exception = new AuthenticationException('Auth failed', 401, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * @test
     */
    public function testCatchByBaseType() {
        $caught = false;

        try {
            throw new RateLimitException('Too many requests', 30);
        } catch (AiException $e) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }

    /**
     * @test
     */
    public function testCatchBySpecificType() {
        $retryAfter = null;

        try {
            throw new RateLimitException('Too many requests', 30);
        } catch (RateLimitException $e) {
            $retryAfter = $e->getRetryAfterSeconds();
        }

        $this->assertEquals(30, $retryAfter);
    }

    /**
     * @test
     */
    public function testHttpException() {
        $exception = new HttpException('Connection timed out', 28);

        $this->assertEquals('Connection timed out', $exception->getMessage());
        $this->assertEquals(28, $exception->getCurlErrorCode());
        $this->assertEquals(28, $exception->getCode());
    }

    /**
     * @test
     */
    public function testInvalidConfigException() {
        $exception = new InvalidConfigException('API key is required', 'api_key');

        $this->assertEquals('API key is required', $exception->getMessage());
        $this->assertEquals('api_key', $exception->getOptionName());
    }

    /**
     * @test
     */
    public function testInvalidConfigExceptionWithoutOption() {
        $exception = new InvalidConfigException('Configuration is invalid');

        $this->assertNull($exception->getOptionName());
    }

    /**
     * @test
     */
    public function testProviderException() {
        $exception = new ProviderException(
            'The model does not exist',
            404,
            'model_not_found'
        );

        $this->assertEquals('The model does not exist', $exception->getMessage());
        $this->assertEquals(404, $exception->getStatusCode());
        $this->assertEquals('model_not_found', $exception->getProviderErrorCode());
        $this->assertEquals(404, $exception->getCode());
    }

    /**
     * @test
     */
    public function testProviderExceptionDefaults() {
        $exception = new ProviderException('Internal error');

        $this->assertEquals(500, $exception->getStatusCode());
        $this->assertNull($exception->getProviderErrorCode());
    }

    /**
     * @test
     */
    public function testRateLimitException() {
        $exception = new RateLimitException('Rate limit exceeded', 60);

        $this->assertEquals('Rate limit exceeded', $exception->getMessage());
        $this->assertEquals(60, $exception->getRetryAfterSeconds());
        $this->assertEquals(429, $exception->getCode());
    }

    /**
     * @test
     */
    public function testRateLimitExceptionWithoutRetryAfter() {
        $exception = new RateLimitException('Too many requests');

        $this->assertNull($exception->getRetryAfterSeconds());
    }

    /**
     * @test
     */
    public function testStreamingException() {
        $previous = new \RuntimeException('Chunk parse error');
        $exception = new StreamingException('Stream interrupted', 0, $previous);

        $this->assertEquals('Stream interrupted', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * @test
     */
    public function testUnsupportedFeatureException() {
        $exception = new UnsupportedFeatureException('image_generation', 'anthropic');

        $this->assertEquals(
            "The 'image_generation' feature is not supported by the 'anthropic' provider.",
            $exception->getMessage()
        );
        $this->assertEquals('image_generation', $exception->getFeature());
        $this->assertEquals('anthropic', $exception->getProviderName());
    }
}
