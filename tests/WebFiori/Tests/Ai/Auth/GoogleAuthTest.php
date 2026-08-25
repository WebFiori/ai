<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Auth;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Auth\GoogleAuth;

/**
 * Basic structural tests for GoogleAuth.
 *
 * These tests verify instantiation and configuration without
 * requiring real Google credentials.
 */
class GoogleAuthTest extends TestCase {
    public function testConstructor_AcceptsNull(): void {
        $auth = new GoogleAuth(credentials: null);

        $this->assertInstanceOf(GoogleAuth::class, $auth);
    }

    public function testConstructor_AcceptsString(): void {
        // Pass a non-existent file path — constructor doesn't validate
        $auth = new GoogleAuth(credentials: '/tmp/non-existent-service-account.json');

        $this->assertInstanceOf(GoogleAuth::class, $auth);
    }

    public function testConstructor_AcceptsArray(): void {
        $auth = new GoogleAuth(credentials: [
            'type' => 'service_account',
            'client_email' => 'test@test.iam.gserviceaccount.com',
            'private_key' => 'fake-key',
        ]);

        $this->assertInstanceOf(GoogleAuth::class, $auth);
    }

    public function testConstructor_WithAccessToken(): void {
        $auth = new GoogleAuth(accessToken: 'ya29.test-token');

        // With a pre-configured token, getAccessToken should return it directly
        $this->assertSame('ya29.test-token', $auth->getAccessToken());
    }

    public function testGetAuthHeaders_WithAccessToken(): void {
        $auth = new GoogleAuth(accessToken: 'ya29.test-header-token');

        $headers = $auth->getAuthHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertSame('Bearer ya29.test-header-token', $headers['Authorization']);
    }
}
