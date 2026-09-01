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
use WebFiori\Ai\Auth\AwsCredentialChain;

/**
 * Offline tests for AwsCredentialChain covering the environment-variable and
 * credentials-file resolution paths.
 *
 * Environment variables and a temporary HOME are manipulated and fully
 * restored in tearDown so no state leaks into other tests. The metadata
 * service path is not exercised (it requires EC2/ECS networking).
 */
class AwsCredentialChainTest extends TestCase {
    /**
     * @var array<string, string|false>
     */
    private array $savedEnv = [];

    private ?string $tmpDir = null;

    protected function setUp(): void {
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_PROFILE', 'HOME'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key); // unset for a clean baseline
        }
    }

    protected function tearDown(): void {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }

        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            @unlink($this->tmpDir.'/.aws/credentials');
            @rmdir($this->tmpDir.'/.aws');
            @rmdir($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    /**
     * Writes a temporary ~/.aws/credentials file and points HOME at it.
     */
    private function writeCredentialsFile(string $content): void {
        $this->tmpDir = sys_get_temp_dir().'/awschain_'.uniqid();
        mkdir($this->tmpDir.'/.aws', 0700, true);
        file_put_contents($this->tmpDir.'/.aws/credentials', $content);
        putenv('HOME='.$this->tmpDir);
    }

    // =========================================================================
    // fromEnvironment()
    // =========================================================================

    public function testFromEnvironment_ResolvesKeys(): void {
        putenv('AWS_ACCESS_KEY_ID=AKIA_ENV');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET_ENV');

        $creds = (new AwsCredentialChain())->fromEnvironment();

        $this->assertNotNull($creds);
        $this->assertSame('AKIA_ENV', $creds['access_key']);
        $this->assertSame('SECRET_ENV', $creds['secret_key']);
        $this->assertNull($creds['session_token']);
    }

    public function testFromEnvironment_IncludesSessionToken(): void {
        putenv('AWS_ACCESS_KEY_ID=AKIA_ENV');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET_ENV');
        putenv('AWS_SESSION_TOKEN=TOKEN_ENV');

        $creds = (new AwsCredentialChain())->fromEnvironment();

        $this->assertSame('TOKEN_ENV', $creds['session_token']);
    }

    public function testFromEnvironment_ReturnsNullWhenMissing(): void {
        $this->assertNull((new AwsCredentialChain())->fromEnvironment());
    }

    // =========================================================================
    // fromCredentialsFile() + parseCredentialsFile()
    // =========================================================================

    public function testFromCredentialsFile_DefaultProfile(): void {
        $this->writeCredentialsFile(<<<INI
        [default]
        aws_access_key_id = AKIA_DEFAULT
        aws_secret_access_key = SECRET_DEFAULT
        INI);

        $creds = (new AwsCredentialChain())->fromCredentialsFile();

        $this->assertNotNull($creds);
        $this->assertSame('AKIA_DEFAULT', $creds['access_key']);
        $this->assertSame('SECRET_DEFAULT', $creds['secret_key']);
    }

    public function testFromCredentialsFile_NamedProfileAndSessionToken(): void {
        putenv('AWS_PROFILE=work');
        $this->writeCredentialsFile(<<<INI
        ; a comment line
        [default]
        aws_access_key_id = AKIA_DEFAULT
        aws_secret_access_key = SECRET_DEFAULT

        [work]
        aws_access_key_id = AKIA_WORK
        aws_secret_access_key = SECRET_WORK
        aws_session_token = TOKEN_WORK
        INI);

        $creds = (new AwsCredentialChain())->fromCredentialsFile();

        $this->assertSame('AKIA_WORK', $creds['access_key']);
        $this->assertSame('SECRET_WORK', $creds['secret_key']);
        $this->assertSame('TOKEN_WORK', $creds['session_token']);
    }

    public function testFromCredentialsFile_ReturnsNullWhenFileMissing(): void {
        // HOME points at a temp dir with no .aws/credentials.
        $this->tmpDir = sys_get_temp_dir().'/awschain_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
        putenv('HOME='.$this->tmpDir);

        $this->assertNull((new AwsCredentialChain())->fromCredentialsFile());
    }

    public function testFromCredentialsFile_ReturnsNullWhenProfileIncomplete(): void {
        $this->writeCredentialsFile(<<<INI
        [default]
        aws_access_key_id = AKIA_ONLY
        INI);

        $this->assertNull((new AwsCredentialChain())->fromCredentialsFile());
    }

    // =========================================================================
    // resolve() precedence
    // =========================================================================

    public function testResolve_PrefersEnvironmentOverFile(): void {
        putenv('AWS_ACCESS_KEY_ID=AKIA_ENV');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET_ENV');
        $this->writeCredentialsFile(<<<INI
        [default]
        aws_access_key_id = AKIA_FILE
        aws_secret_access_key = SECRET_FILE
        INI);

        $creds = (new AwsCredentialChain())->resolve();

        $this->assertSame('AKIA_ENV', $creds['access_key']);
    }

    public function testResolve_FallsBackToFile(): void {
        $this->writeCredentialsFile(<<<INI
        [default]
        aws_access_key_id = AKIA_FILE
        aws_secret_access_key = SECRET_FILE
        INI);

        $creds = (new AwsCredentialChain())->resolve();

        $this->assertSame('AKIA_FILE', $creds['access_key']);
    }

    public function testGetCredentialsFilePath_UsesHome(): void {
        putenv('HOME=/tmp/fake-home');
        $path = (new AwsCredentialChain())->getCredentialsFilePath();

        // On non-Windows this is HOME/.aws/credentials.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('/tmp/fake-home/.aws/credentials', $path);
        } else {
            $this->assertStringContainsString('.aws', $path);
        }
    }
}
