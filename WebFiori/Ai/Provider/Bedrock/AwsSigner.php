<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Bedrock;

/**
 * AWS Signature Version 4 signing utility.
 *
 * Implements the AWS SigV4 signing process for authenticating requests
 * to AWS services without requiring the full AWS SDK.
 *
 * @author Ibrahim
 */
class AwsSigner {
    /**
     * AWS access key ID.
     *
     * @var string
     */
    private string $accessKey;

    /**
     * AWS region.
     *
     * @var string
     */
    private string $region;

    /**
     * AWS secret access key.
     *
     * @var string
     */
    private string $secretKey;

    /**
     * AWS service name.
     *
     * @var string
     */
    private string $service;

    /**
     * AWS session token (for temporary credentials from IAM roles).
     *
     * @var string|null
     */
    private ?string $sessionToken;

    /**
     * Creates a new AwsSigner instance.
     *
     * @param string $accessKey AWS access key ID.
     * @param string $secretKey AWS secret access key.
     * @param string $region AWS region (e.g., 'us-east-1').
     * @param string $service AWS service name (e.g., 'bedrock').
     * @param string|null $sessionToken Optional session token for temporary credentials.
     */
    public function __construct(string $accessKey, string $secretKey, string $region, string $service = 'bedrock', ?string $sessionToken = null) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region;
        $this->service = $service;
        $this->sessionToken = $sessionToken;
    }

    /**
     * Signs an HTTP request with AWS Signature Version 4.
     *
     * @param string $method HTTP method (GET, POST, etc.).
     * @param string $url Full request URL.
     * @param array<string, string> $headers Existing headers to include in signing.
     * @param string $body Request body content.
     *
     * @return array<string, string> Headers with Authorization and other required
     *         SigV4 headers added.
     */
    public function sign(string $method, string $url, array $headers, string $body): array {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'];
        $path = $parsedUrl['path'] ?? '/';
        $query = $parsedUrl['query'] ?? '';

        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        // Add required headers
        $headers['Host'] = $host;
        $headers['X-Amz-Date'] = $timestamp;

        // Session token for temporary credentials (IAM roles)
        if ($this->sessionToken !== null) {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }

        // Hash the payload
        $payloadHash = hash('sha256', $body);
        $headers['X-Amz-Content-Sha256'] = $payloadHash;

        // Create canonical request
        $canonicalHeaders = $this->buildCanonicalHeaders($headers);
        $signedHeaders = $this->buildSignedHeadersList($headers);

        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncodePath($path),
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "$date/{$this->region}/{$this->service}/aws4_request";
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);

        $stringToSign = implode("\n", [
            $algorithm,
            $timestamp,
            $credentialScope,
            $hashedCanonicalRequest,
        ]);

        // Calculate signature
        $signingKey = $this->getSigningKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        // Build authorization header
        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $this->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature
        );

        return $headers;
    }

    /**
     * Builds the canonical headers string for signing.
     *
     * @param array<string, string> $headers The headers to include.
     *
     * @return string The canonical headers string (ends with newline).
     */
    private function buildCanonicalHeaders(array $headers): string {
        $canonical = [];

        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim($value);
        }

        ksort($canonical);

        $result = '';

        foreach ($canonical as $name => $value) {
            $result .= "$name:$value\n";
        }

        return $result;
    }

    /**
     * Builds the signed headers list for the Authorization header.
     *
     * @param array<string, string> $headers The headers being signed.
     *
     * @return string Semicolon-separated list of signed header names.
     */
    private function buildSignedHeadersList(array $headers): string {
        $names = array_map('strtolower', array_keys($headers));
        sort($names);

        return implode(';', $names);
    }

    /**
     * Derives the signing key for the given date.
     *
     * @param string $date The date in YYYYMMDD format.
     *
     * @return string The binary signing key.
     */
    private function getSigningKey(string $date): string {
        $kDate = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * URI-encodes the path component according to AWS requirements.
     *
     * @param string $path The path to encode.
     *
     * @return string The encoded path.
     */
    private function uriEncodePath(string $path): string {
        $segments = explode('/', $path);
        $encoded = [];

        foreach ($segments as $segment) {
            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }
}
