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
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;

/**
 * Extended tests for GoogleAuth with FakeHttpClient.
 */
class GoogleAuthExtendedTest extends TestCase {
    private array $savedEnv = [];

    protected function setUp(): void {
        $this->savedEnv['GOOGLE_APPLICATION_CREDENTIALS'] = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        putenv('GOOGLE_APPLICATION_CREDENTIALS');
    }

    protected function tearDown(): void {
        $val = $this->savedEnv['GOOGLE_APPLICATION_CREDENTIALS'];
        if ($val === false) {
            putenv('GOOGLE_APPLICATION_CREDENTIALS');
        } else {
            putenv("GOOGLE_APPLICATION_CREDENTIALS={$val}");
        }
    }

    /**
     * @test
     */
    public function testPreConfiguredTokenReturned() {
        $auth = new GoogleAuth(
            accessToken: 'my-static-token'
        );

        $this->assertEquals('my-static-token', $auth->getAccessToken());
    }

    /**
     * @test
     */
    public function testPreConfiguredTokenReturnedRepeatedly() {
        $auth = new GoogleAuth(
            accessToken: 'my-token'
        );

        // Should return same token on repeated calls
        $this->assertEquals('my-token', $auth->getAccessToken());
        $this->assertEquals('my-token', $auth->getAccessToken());
    }

    /**
     * @test
     */
    public function testGetAuthHeaders() {
        $auth = new GoogleAuth(accessToken: 'header-token');
        $headers = $auth->getAuthHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals('Bearer header-token', $headers['Authorization']);
    }

    /**
     * @test
     */
    public function testServiceAccountCredentialsWithHttpClient() {
        // Generate a real RSA key for signing
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKeyPem);

        $credentials = [
            'type' => 'service_account',
            'client_email' => 'test@test.iam.gserviceaccount.com',
            'private_key' => $privateKeyPem,
        ];

        $httpClient = new FakeHttpClient();
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'access_token' => 'generated-token-123',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ])));

        $auth = new GoogleAuth(
            credentials: $credentials,
            httpClient: $httpClient
        );

        $token = $auth->getAccessToken();
        $this->assertEquals('generated-token-123', $token);

        // Verify the token exchange request was sent
        $request = $httpClient->getLastRequest();
        $this->assertStringContainsString('oauth2.googleapis.com/token', $request->getUrl());
        $this->assertStringContainsString('jwt-bearer', $request->getBody());
    }

    /**
     * @test
     */
    public function testServiceAccountTokenCached() {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKeyPem);

        $credentials = [
            'type' => 'service_account',
            'client_email' => 'test@test.iam.gserviceaccount.com',
            'private_key' => $privateKeyPem,
        ];

        $httpClient = new FakeHttpClient();
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'access_token' => 'cached-token',
            'expires_in' => 3600,
        ])));

        $auth = new GoogleAuth(
            credentials: $credentials,
            httpClient: $httpClient
        );

        // First call generates token
        $token1 = $auth->getAccessToken();
        $this->assertEquals('cached-token', $token1);

        // Second call should use cache (no second response queued)
        $token2 = $auth->getAccessToken();
        $this->assertEquals('cached-token', $token2);

        // Only one request should have been made
        $this->assertCount(1, $httpClient->getRequests());
    }

    /**
     * @test
     */
    public function testServiceAccountTokenExchangeFailure() {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKeyPem);

        $credentials = [
            'type' => 'service_account',
            'client_email' => 'test@test.iam.gserviceaccount.com',
            'private_key' => $privateKeyPem,
        ];

        $httpClient = new FakeHttpClient();
        $httpClient->addResponse(new HttpResponse(400, [], json_encode([
            'error' => 'invalid_grant',
        ])));

        $auth = new GoogleAuth(
            credentials: $credentials,
            httpClient: $httpClient
        );

        $this->expectException(AuthenticationException::class);
        $auth->getAccessToken();
    }

    /**
     * @test
     */
    public function testInvalidPrivateKeyThrowsAuth() {
        $credentials = [
            'type' => 'service_account',
            'client_email' => 'test@test.iam.gserviceaccount.com',
            'private_key' => 'not-a-valid-key',
        ];

        $httpClient = new FakeHttpClient();

        $auth = new GoogleAuth(
            credentials: $credentials,
            httpClient: $httpClient
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Failed to sign JWT');
        $auth->getAccessToken();
    }

    /**
     * @test
     */
    public function testServiceAccountFromFile() {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKeyPem);

        $tmpFile = tempnam(sys_get_temp_dir(), 'google_sa_');
        file_put_contents($tmpFile, json_encode([
            'type' => 'service_account',
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key' => $privateKeyPem,
        ]));

        try {
            $httpClient = new FakeHttpClient();
            $httpClient->addResponse(new HttpResponse(200, [], json_encode([
                'access_token' => 'from-file-token',
                'expires_in' => 3600,
            ])));

            $auth = new GoogleAuth(
                credentials: $tmpFile,
                httpClient: $httpClient
            );

            $token = $auth->getAccessToken();
            $this->assertEquals('from-file-token', $token);
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @test
     */
    public function testGcloudAuthorizedUserRefresh() {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gcloud_');
        file_put_contents($tmpFile, json_encode([
            'type' => 'authorized_user',
            'client_id' => 'client_id_123',
            'client_secret' => 'client_secret_456',
            'refresh_token' => 'refresh_token_789',
        ]));

        try {
            putenv("GOOGLE_APPLICATION_CREDENTIALS={$tmpFile}");

            $httpClient = new FakeHttpClient();
            $httpClient->addResponse(new HttpResponse(200, [], json_encode([
                'access_token' => 'refreshed-token',
                'expires_in' => 3600,
            ])));

            $auth = new GoogleAuth(
                credentials: null,
                httpClient: $httpClient
            );

            $token = $auth->getAccessToken();
            $this->assertEquals('refreshed-token', $token);

            // Verify refresh_token was used
            $request = $httpClient->getLastRequest();
            $this->assertStringContainsString('refresh_token', $request->getBody());
            $this->assertStringContainsString('client_id_123', $request->getBody());
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @test
     */
    public function testGcloudAuthorizedUserRefreshFails() {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gcloud_');
        file_put_contents($tmpFile, json_encode([
            'type' => 'authorized_user',
            'client_id' => 'client_id',
            'client_secret' => 'secret',
            'refresh_token' => 'token',
        ]));

        try {
            putenv("GOOGLE_APPLICATION_CREDENTIALS={$tmpFile}");

            $httpClient = new FakeHttpClient();
            // First response: refresh fails
            $httpClient->addResponse(new HttpResponse(401, [], json_encode([
                'error' => 'invalid_client',
            ])));
            // Additional responses for any subsequent ADC attempts
            $httpClient->addResponse(new HttpResponse(401, [], json_encode([
                'error' => 'invalid_client',
            ])));
            $httpClient->addResponse(new HttpResponse(401, [], json_encode([
                'error' => 'invalid_client',
            ])));

            $auth = new GoogleAuth(
                credentials: null,
                httpClient: $httpClient
            );

            // If refresh fails, it should try next steps in the chain
            // Eventually throws because no other credentials are available
            $this->expectException(AuthenticationException::class);
            $auth->getAccessToken();
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * @test
     */
    public function testNoCredentialsThrowsException() {
        // Override HOME so gcloud default credentials path doesn't exist
        $savedHome = getenv('HOME');
        putenv('HOME=/tmp/nonexistent_home_' . uniqid());

        try {
            $auth = new GoogleAuth(
                credentials: null,
                accessToken: null,
                httpClient: new FakeHttpClient()
            );

            $this->expectException(AuthenticationException::class);
            $this->expectExceptionMessage('No Google credentials found');
            $auth->getAccessToken();
        } finally {
            if ($savedHome !== false) {
                putenv("HOME={$savedHome}");
            } else {
                putenv('HOME');
            }
        }
    }

    /**
     * @test
     */
    public function testGenerativeLanguageScopeIncluded() {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKeyPem);

        $credentials = [
            'type' => 'service_account',
            'client_email' => 'test@test.iam.gserviceaccount.com',
            'private_key' => $privateKeyPem,
        ];

        $httpClient = new FakeHttpClient();
        $httpClient->addResponse(new HttpResponse(200, [], json_encode([
            'access_token' => 'token-with-gen-lang-scope',
            'expires_in' => 3600,
        ])));

        $auth = new GoogleAuth(
            credentials: $credentials,
            includeGenerativeLanguageScope: true,
            httpClient: $httpClient
        );

        $token = $auth->getAccessToken();
        $this->assertEquals('token-with-gen-lang-scope', $token);
    }
}
