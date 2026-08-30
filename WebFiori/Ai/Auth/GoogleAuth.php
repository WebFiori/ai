<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Auth;

use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;

/**
 * Standalone Google authentication handler.
 *
 * Implements the full Application Default Credentials (ADC) chain:
 * 1. Pre-configured access token (if provided)
 * 2. Service account credentials (from config or GOOGLE_APPLICATION_CREDENTIALS)
 * 3. gcloud default credentials (~/.config/gcloud/application_default_credentials.json)
 * 4. GCE/GKE/Cloud Run metadata server
 *
 * @author Ibrahim
 */
class GoogleAuth {
    /**
     * Cached OAuth2 access token.
     *
     * @var string|null
     */
    private ?string $cachedToken = null;

    /**
     * Path to service account JSON file, or credentials as array.
     *
     * @var string|array<string, mixed>|null
     */
    private string|array|null $credentials;

    /**
     * Optional HTTP client for token exchange requests.
     *
     * @var HttpClientInterface|null
     */
    private ?HttpClientInterface $httpClient;

    /**
     * Whether to include generative-language scope (for Gemini API).
     *
     * @var bool
     */
    private bool $includeGenerativeLanguageScope;

    /**
     * Pre-configured access token (bypasses credential resolution).
     *
     * @var string|null
     */
    private ?string $preConfiguredToken;

    /**
     * Token expiration timestamp.
     *
     * @var int
     */
    private int $tokenExpiry = 0;

    /**
     * Creates a new GoogleAuth instance.
     *
     * @param string|array<string, mixed>|null $credentials Service account JSON path or array.
     * @param string|null $accessToken Pre-fetched OAuth2 access token.
     * @param bool $includeGenerativeLanguageScope Whether to include generative-language scope.
     * @param HttpClientInterface|null $httpClient HTTP client for token exchange.
     */
    public function __construct(
        string|array|null $credentials = null,
        ?string $accessToken = null,
        bool $includeGenerativeLanguageScope = false,
        ?HttpClientInterface $httpClient = null
    ) {
        $this->credentials = $credentials;
        $this->preConfiguredToken = $accessToken;
        $this->includeGenerativeLanguageScope = $includeGenerativeLanguageScope;
        $this->httpClient = $httpClient;
    }

    /**
     * Returns the OAuth2 access token, resolving via ADC chain if needed.
     *
     * @return string The access token.
     *
     * @throws AuthenticationException If no credentials can be resolved.
     */
    public function getAccessToken(): string {
        // Use pre-configured access token
        if ($this->preConfiguredToken !== null) {
            return $this->preConfiguredToken;
        }

        // Check cached token
        if ($this->cachedToken !== null && time() < $this->tokenExpiry) {
            return $this->cachedToken;
        }

        // Generate from service account credentials (explicit config)
        $credentials = $this->credentials;

        if (is_string($credentials) && is_file($credentials)) {
            $credentials = json_decode(file_get_contents($credentials), true);
        }

        if (is_array($credentials)) {
            $this->cachedToken = $this->generateAccessToken($credentials);
            $this->tokenExpiry = time() + 3500;

            return $this->cachedToken;
        }

        // ADC: GOOGLE_APPLICATION_CREDENTIALS environment variable
        $envPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        if (!empty($envPath) && is_file($envPath)) {
            $credentials = json_decode(file_get_contents($envPath), true);

            if (is_array($credentials)) {
                if (($credentials['type'] ?? '') === 'authorized_user') {
                    $token = $this->refreshGcloudToken($credentials);

                    if ($token !== null) {
                        $this->cachedToken = $token;
                        $this->tokenExpiry = time() + 3500;

                        return $this->cachedToken;
                    }
                } else {
                    $this->cachedToken = $this->generateAccessToken($credentials);
                    $this->tokenExpiry = time() + 3500;

                    return $this->cachedToken;
                }
            }
        }

        // ADC: gcloud default credentials file
        $gcloudPath = $this->getGcloudDefaultCredentialsPath();

        if (is_file($gcloudPath)) {
            $gcloudCreds = json_decode(file_get_contents($gcloudPath), true);

            if (is_array($gcloudCreds)) {
                if (($gcloudCreds['type'] ?? '') === 'authorized_user') {
                    $token = $this->refreshGcloudToken($gcloudCreds);

                    if ($token !== null) {
                        $this->cachedToken = $token;
                        $this->tokenExpiry = time() + 3500;

                        return $this->cachedToken;
                    }
                } else {
                    $this->cachedToken = $this->generateAccessToken($gcloudCreds);
                    $this->tokenExpiry = time() + 3500;

                    return $this->cachedToken;
                }
            }
        }

        // ADC: GCE/GKE/Cloud Run metadata server
        $metadataToken = $this->fetchFromMetadataServer();

        if ($metadataToken !== null) {
            $this->cachedToken = $metadataToken['access_token'];
            $this->tokenExpiry = time() + (int) ($metadataToken['expires_in'] ?? 3500) - 60;

            return $this->cachedToken;
        }

        throw new AuthenticationException(
            'No Google credentials found. Provide "api_key", "access_token", "credentials", '.
            'set GOOGLE_APPLICATION_CREDENTIALS, run "gcloud auth application-default login", '.
            'or run on GCE/GKE/Cloud Run.',
            401
        );
    }

    /**
     * Returns authorization headers for authenticated requests.
     *
     * @return array<string, string> Headers with Bearer token.
     */
    public function getAuthHeaders(): array {
        return ['Authorization' => 'Bearer '.$this->getAccessToken()];
    }

    /**
     * Fetches an access token from the GCE metadata server.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    private function fetchFromMetadataServer(): ?array {
        $url = 'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Metadata-Flavor: Google\r\n",
                'timeout' => 2,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return $data;
    }

    /**
     * Generates an OAuth2 access token from service account credentials.
     *
     * Creates a self-signed JWT and exchanges it for an access token
     * via Google's token endpoint.
     *
     * @param array<string, string> $credentials The service account credentials.
     *
     * @return string The access token.
     *
     * @throws AuthenticationException If token generation fails.
     */
    private function generateAccessToken(array $credentials): string {
        $now = time();
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);

        $scope = 'https://www.googleapis.com/auth/cloud-platform';

        if ($this->includeGenerativeLanguageScope) {
            $scope .= ' https://www.googleapis.com/auth/generative-language';
        }

        $claim = json_encode([
            'iss' => $credentials['client_email'] ?? '',
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $base64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $base64Claim = rtrim(strtr(base64_encode($claim), '+/', '-_'), '=');
        $signInput = $base64Header.'.'.$base64Claim;

        $privateKey = $credentials['private_key'] ?? '';

        if (!is_string($privateKey) || !str_contains($privateKey, 'PRIVATE KEY')) {
            throw new AuthenticationException(
                'Invalid or missing private key in Google service account credentials.',
                401
            );
        }

        $keyResource = openssl_pkey_get_private($privateKey);

        if ($keyResource === false) {
            throw new AuthenticationException(
                'Invalid private key in Google service account credentials.',
                401
            );
        }

        $signature = '';
        $success = openssl_sign($signInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);

        if (!$success) {
            throw new AuthenticationException(
                'Failed to sign JWT for Google authentication.',
                401
            );
        }

        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt = $signInput.'.'.$base64Signature;

        // Exchange JWT for access token
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenBody = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($this->httpClient !== null) {
            $tokenRequest = new HttpRequest(
                'POST',
                $tokenUrl,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                $tokenBody
            );

            $tokenResponse = $this->httpClient->send($tokenRequest);

            if (!$tokenResponse->isSuccess()) {
                throw new AuthenticationException(
                    'Failed to obtain access token from Google: '.$tokenResponse->getBody(),
                    $tokenResponse->getStatusCode()
                );
            }

            $tokenData = $tokenResponse->getJson();

            return $tokenData['access_token'] ?? '';
        }

        // Fallback to cURL if no HTTP client provided
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($response === false || $httpCode >= 400) {
            throw new AuthenticationException(
                'Failed to obtain access token from Google: '.($response ?: 'curl error'),
                $httpCode ?: 401
            );
        }

        $tokenData = json_decode($response, true);

        return $tokenData['access_token'] ?? '';
    }

    /**
     * Returns the path to the gcloud application default credentials file.
     *
     * @return string
     */
    private function getGcloudDefaultCredentialsPath(): string {
        if (PHP_OS_FAMILY === 'Windows') {
            $appData = getenv('APPDATA') ?: '';

            return $appData.DIRECTORY_SEPARATOR.'gcloud'.DIRECTORY_SEPARATOR.'application_default_credentials.json';
        }

        $home = getenv('HOME') ?: '';

        return $home.'/.config/gcloud/application_default_credentials.json';
    }

    /**
     * Refreshes an access token using gcloud authorized_user credentials.
     *
     * @param array<string, string> $creds The authorized_user credentials.
     *
     * @return string|null The refreshed access token, or null on failure.
     */
    private function refreshGcloudToken(array $creds): ?string {
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenBody = http_build_query([
            'client_id' => $creds['client_id'] ?? '',
            'client_secret' => $creds['client_secret'] ?? '',
            'refresh_token' => $creds['refresh_token'] ?? '',
            'grant_type' => 'refresh_token',
        ]);

        if ($this->httpClient !== null) {
            $tokenRequest = new HttpRequest(
                'POST',
                $tokenUrl,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                $tokenBody
            );

            $response = $this->httpClient->send($tokenRequest);

            if (!$response->isSuccess()) {
                return null;
            }

            $data = $response->getJson();

            return $data['access_token'] ?? null;
        }

        // Fallback to cURL if no HTTP client provided
        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($response === false || $httpCode >= 400) {
            return null;
        }

        $data = json_decode($response, true);

        return $data['access_token'] ?? null;
    }
}
