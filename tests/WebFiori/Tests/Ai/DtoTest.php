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
use WebFiori\Ai\EmbeddingResponse;
use WebFiori\Ai\GeneratedImage;
use WebFiori\Ai\ImageRequest;
use WebFiori\Ai\ImageResponse;
use WebFiori\Ai\Usage;

/**
 * Unit tests for embedding and image DTOs.
 *
 * @author Ibrahim
 */
class DtoTest extends TestCase {
    /**
     * @test
     */
    public function testEmbeddingResponseBatch() {
        $vectors = [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
        ];
        $response = new EmbeddingResponse($vectors, 'text-embedding-3-small', new Usage(10, 0));

        $this->assertCount(2, $response->getVectors());
        $this->assertEquals(3, $response->getDimensions());
        $this->assertEquals([0.1, 0.2, 0.3], $response->getVector());
        $this->assertEquals('text-embedding-3-small', $response->getModel());
        $this->assertEquals(10, $response->getUsage()->getPromptTokens());
    }

    /**
     * @test
     */
    public function testEmbeddingResponseEmpty() {
        $response = new EmbeddingResponse([], 'text-embedding-3-small');

        $this->assertEquals(0, $response->getDimensions());
        $this->assertNull($response->getUsage());

        $this->expectException(\RuntimeException::class);
        $response->getVector();
    }

    /**
     * @test
     */
    public function testGeneratedImage() {
        $image = new GeneratedImage('https://example.com/image.png', null, 'A sunset over mountains');

        $this->assertEquals('https://example.com/image.png', $image->getUrl());
        $this->assertNull($image->getBase64());
        $this->assertEquals('A sunset over mountains', $image->getRevisedPrompt());
    }

    /**
     * @test
     */
    public function testGeneratedImageBase64() {
        $image = new GeneratedImage(null, 'iVBORw0KGgo=', null);

        $this->assertNull($image->getUrl());
        $this->assertEquals('iVBORw0KGgo=', $image->getBase64());
        $this->assertNull($image->getRevisedPrompt());
    }

    /**
     * @test
     */
    public function testImageRequestDefaults() {
        $request = new ImageRequest('A cat wearing a hat');

        $this->assertEquals('A cat wearing a hat', $request->getPrompt());
        $this->assertEquals('1024x1024', $request->getSize());
        $this->assertEquals(1, $request->getCount());
        $this->assertEquals('standard', $request->getQuality());
        $this->assertEquals('url', $request->getFormat());
        $this->assertNull($request->getStyle());
        $this->assertNull($request->getNegativePrompt());
    }

    /**
     * @test
     */
    public function testImageRequestCustom() {
        $request = new ImageRequest(
            prompt: 'A futuristic city',
            size: '1792x1024',
            count: 2,
            quality: 'hd',
            format: 'base64',
            style: 'vivid',
            negativePrompt: 'blurry, low quality'
        );

        $this->assertEquals('A futuristic city', $request->getPrompt());
        $this->assertEquals('1792x1024', $request->getSize());
        $this->assertEquals(2, $request->getCount());
        $this->assertEquals('hd', $request->getQuality());
        $this->assertEquals('base64', $request->getFormat());
        $this->assertEquals('vivid', $request->getStyle());
        $this->assertEquals('blurry, low quality', $request->getNegativePrompt());
    }

    /**
     * @test
     */
    public function testImageResponse() {
        $images = [
            new GeneratedImage('https://example.com/1.png'),
            new GeneratedImage('https://example.com/2.png'),
        ];
        $response = new ImageResponse($images, 'dall-e-3');

        $this->assertCount(2, $response->getImages());
        $this->assertEquals('dall-e-3', $response->getModel());
        $this->assertEquals('https://example.com/1.png', $response->getImages()[0]->getUrl());
    }
}
