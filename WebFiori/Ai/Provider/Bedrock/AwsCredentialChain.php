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
 * Resolves AWS credentials using the standard credential chain.
 *
 * Resolution order (highest to lowest priority):
 * 1. Environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_SESSION_TOKEN)
 * 2. AWS credentials file (~/.aws/credentials or %USERPROFILE%\.aws\credentials)
 *    Respects AWS_PROFILE environment variable for named profiles.
 * 3. EC2/ECS instance metadata service (IAM role credentials)
 *
 * This matches the resolution order used by the official AWS SDK.
 *
 * @author Ibrahim
 */
class AwsCredentialChain {
    /**
     * Metadata service base URL.
     *
     * @var string
     */
    private const METADATA_BASE_URL = 'http://169.254.169.254/latest/meta-data/iam/security-credentials/';

    /**
     * Metadata service connect timeout in seconds.
     *
     * @var int
     */
    private const METADATA_TIMEOUT = 2;

    /**
     * Metadata service token URL (IMDSv2).
     *
     * @var string
     */
    private const METADATA_TOKEN_URL = 'http://169.254.169.254/latest/api/token';

    /**
     * Resolves credentials from the AWS credentials file.
     *
     * Respects AWS_PROFILE environment variable. Defaults to 'default' profile.
     *
     * @return array{access_key: string, secret_key: string, session_token: string|null}|null
     */
    public function fromCredentialsFile(): ?array {
        $path = $this->getCredentialsFilePath();

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $profile = getenv('AWS_PROFILE') ?: 'default';

        return $this->parseCredentialsFile($content, $profile);
    }

    /**
     * Resolves credentials from environment variables.
     *
     * @return array{access_key: string, secret_key: string, session_token: string|null}|null
     */
    public function fromEnvironment(): ?array {
        $accessKey = getenv('AWS_ACCESS_KEY_ID');
        $secretKey = getenv('AWS_SECRET_ACCESS_KEY');

        if (empty($accessKey) || empty($secretKey)) {
            return null;
        }

        $sessionToken = getenv('AWS_SESSION_TOKEN') ?: null;

        return [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'session_token' => $sessionToken ?: null,
        ];
    }

    /**
     * Resolves credentials from the EC2/ECS instance metadata service (IMDSv2).
     *
     * @return array{access_key: string, secret_key: string, session_token: string|null}|null
     */
    public function fromMetadataService(): ?array {
        // Step 1: Get IMDSv2 token
        $token = $this->fetchMetadataToken();

        if ($token === null) {
            return null;
        }

        // Step 2: Get IAM role name
        $roleName = $this->fetchMetadata(self::METADATA_BASE_URL, $token);

        if ($roleName === null || trim($roleName) === '') {
            return null;
        }

        $roleName = trim(explode("\n", $roleName)[0]);

        // Step 3: Get credentials for the role
        $credentialsJson = $this->fetchMetadata(self::METADATA_BASE_URL.$roleName, $token);

        if ($credentialsJson === null) {
            return null;
        }

        $data = json_decode($credentialsJson, true);

        if (!is_array($data) || ($data['Code'] ?? '') !== 'Success') {
            return null;
        }

        return [
            'access_key' => $data['AccessKeyId'] ?? '',
            'secret_key' => $data['SecretAccessKey'] ?? '',
            'session_token' => $data['Token'] ?? null,
        ];
    }

    /**
     * Returns the path to the AWS credentials file.
     *
     * @return string
     */
    public function getCredentialsFilePath(): string {
        if (PHP_OS_FAMILY === 'Windows') {
            $home = getenv('USERPROFILE') ?: '';

            return $home.DIRECTORY_SEPARATOR.'.aws'.DIRECTORY_SEPARATOR.'credentials';
        }

        $home = getenv('HOME') ?: '';

        return $home.'/.aws/credentials';
    }

    /**
     * Resolves credentials from the standard AWS credential chain.
     *
     * @return array{access_key: string, secret_key: string, session_token: string|null}|null
     *         Resolved credentials, or null if none found.
     */
    public function resolve(): ?array {
        // 1. Environment variables
        $creds = $this->fromEnvironment();

        if ($creds !== null) {
            return $creds;
        }

        // 2. Credentials file
        $creds = $this->fromCredentialsFile();

        if ($creds !== null) {
            return $creds;
        }

        // 3. Instance metadata service
        return $this->fromMetadataService();
    }

    /**
     * Fetches a value from the metadata service.
     *
     * @param string $url The metadata URL.
     * @param string $token IMDSv2 session token.
     *
     * @return string|null The response body, or null on failure.
     */
    private function fetchMetadata(string $url, string $token): ?string {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "X-aws-ec2-metadata-token: {$token}\r\n",
                'timeout' => self::METADATA_TIMEOUT,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        return $result !== false ? $result : null;
    }

    /**
     * Fetches the IMDSv2 session token.
     *
     * @return string|null The session token, or null if metadata service is unavailable.
     */
    private function fetchMetadataToken(): ?string {
        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => "X-aws-ec2-metadata-token-ttl-seconds: 21600\r\n",
                'timeout' => self::METADATA_TIMEOUT,
            ],
        ]);

        $token = @file_get_contents(self::METADATA_TOKEN_URL, false, $context);

        return $token !== false ? $token : null;
    }

    /**
     * Parses an AWS credentials file and extracts the specified profile.
     *
     * @param string $content File content.
     * @param string $profile Profile name to extract.
     *
     * @return array{access_key: string, secret_key: string, session_token: string|null}|null
     */
    private function parseCredentialsFile(string $content, string $profile): ?array {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $currentProfile = null;
        $accessKey = null;
        $secretKey = null;
        $sessionToken = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            // Profile header
            if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
                // If we just finished our target profile, stop
                if ($currentProfile === $profile && $accessKey !== null && $secretKey !== null) {
                    break;
                }

                $currentProfile = trim($matches[1]);

                continue;
            }

            // Key-value pair
            if ($currentProfile === $profile && str_contains($line, '=')) {
                [$key, $value] = array_map('trim', explode('=', $line, 2));

                match ($key) {
                    'aws_access_key_id' => $accessKey = $value,
                    'aws_secret_access_key' => $secretKey = $value,
                    'aws_session_token' => $sessionToken = $value,
                    default => null,
                };
            }
        }

        if ($accessKey === null || $secretKey === null) {
            return null;
        }

        return [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'session_token' => $sessionToken,
        ];
    }
}
