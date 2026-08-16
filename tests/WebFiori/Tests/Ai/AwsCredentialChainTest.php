<?php

namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Provider\Bedrock\AwsCredentialChain;

/**
 * Tests for AwsCredentialChain.
 */
class AwsCredentialChainTest extends TestCase {
    private array $savedEnv = [];
    private string $tmpCredFile;

    protected function setUp(): void {
        // Save env vars we will modify
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_PROFILE'] as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var); // clear
        }

        $this->tmpCredFile = sys_get_temp_dir() . '/aws_creds_test_' . uniqid();
    }

    protected function tearDown(): void {
        // Restore env vars
        foreach ($this->savedEnv as $var => $value) {
            if ($value === false) {
                putenv($var);
            } else {
                putenv("{$var}={$value}");
            }
        }

        if (file_exists($this->tmpCredFile)) {
            unlink($this->tmpCredFile);
        }
    }

    // =========================================================================
    // fromEnvironment()
    // =========================================================================

    public function testFromEnvironmentReturnsCredentials(): void {
        putenv('AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
        putenv('AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfi');

        $chain = new AwsCredentialChain();
        $creds = $chain->fromEnvironment();

        $this->assertNotNull($creds);
        $this->assertEquals('AKIAIOSFODNN7EXAMPLE', $creds['access_key']);
        $this->assertEquals('wJalrXUtnFEMI/K7MDENG/bPxRfi', $creds['secret_key']);
        $this->assertNull($creds['session_token']);
    }

    public function testFromEnvironmentIncludesSessionToken(): void {
        putenv('AWS_ACCESS_KEY_ID=AKID');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET');
        putenv('AWS_SESSION_TOKEN=TOKEN123');

        $chain = new AwsCredentialChain();
        $creds = $chain->fromEnvironment();

        $this->assertNotNull($creds);
        $this->assertEquals('TOKEN123', $creds['session_token']);
    }

    public function testFromEnvironmentReturnsNullWhenMissing(): void {
        $chain = new AwsCredentialChain();

        $this->assertNull($chain->fromEnvironment());
    }

    public function testFromEnvironmentReturnsNullWhenOnlyAccessKey(): void {
        putenv('AWS_ACCESS_KEY_ID=AKID');
        // No secret key

        $chain = new AwsCredentialChain();

        $this->assertNull($chain->fromEnvironment());
    }

    // =========================================================================
    // fromCredentialsFile()
    // =========================================================================

    public function testFromCredentialsFileReadsDefaultProfile(): void {
        file_put_contents($this->tmpCredFile, "[default]\naws_access_key_id=DEFAULTKEY\naws_secret_access_key=DEFAULTSECRET\n");

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $creds = $chain->fromCredentialsFile();

        $this->assertNotNull($creds);
        $this->assertEquals('DEFAULTKEY', $creds['access_key']);
        $this->assertEquals('DEFAULTSECRET', $creds['secret_key']);
        $this->assertNull($creds['session_token']);
    }

    public function testFromCredentialsFileReadsNamedProfile(): void {
        file_put_contents($this->tmpCredFile, "[default]\naws_access_key_id=DEFAULTKEY\naws_secret_access_key=DEFAULTSECRET\n\n[staging]\naws_access_key_id=STAGINGKEY\naws_secret_access_key=STAGINGSECRET\n");

        putenv('AWS_PROFILE=staging');

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $creds = $chain->fromCredentialsFile();

        $this->assertNotNull($creds);
        $this->assertEquals('STAGINGKEY', $creds['access_key']);
        $this->assertEquals('STAGINGSECRET', $creds['secret_key']);
    }

    public function testFromCredentialsFileIncludesSessionToken(): void {
        file_put_contents($this->tmpCredFile, "[default]\naws_access_key_id=KEY\naws_secret_access_key=SECRET\naws_session_token=SESSIONTOKEN\n");

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $creds = $chain->fromCredentialsFile();

        $this->assertEquals('SESSIONTOKEN', $creds['session_token']);
    }

    public function testFromCredentialsFileReturnsNullWhenFileMissing(): void {
        $chain = new class() extends AwsCredentialChain {
            public function getCredentialsFilePath(): string { return '/nonexistent/path/.aws/credentials'; }
        };

        $this->assertNull($chain->fromCredentialsFile());
    }

    public function testFromCredentialsFileReturnsNullWhenProfileMissing(): void {
        file_put_contents($this->tmpCredFile, "[default]\naws_access_key_id=KEY\naws_secret_access_key=SECRET\n");

        putenv('AWS_PROFILE=nonexistent');

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $this->assertNull($chain->fromCredentialsFile());
    }

    public function testFromCredentialsFileHandlesWindowsLineEndings(): void {
        file_put_contents($this->tmpCredFile, "[default]\r\naws_access_key_id=WINKEY\r\naws_secret_access_key=WINSECRET\r\n");

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $creds = $chain->fromCredentialsFile();

        $this->assertNotNull($creds);
        $this->assertEquals('WINKEY', $creds['access_key']);
    }

    public function testFromCredentialsFileIgnoresComments(): void {
        file_put_contents($this->tmpCredFile, "# This is a comment\n[default]\n; Also a comment\naws_access_key_id=KEY\naws_secret_access_key=SECRET\n");

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $creds = $chain->fromCredentialsFile();

        $this->assertNotNull($creds);
        $this->assertEquals('KEY', $creds['access_key']);
    }

    // =========================================================================
    // resolve() priority
    // =========================================================================

    public function testResolveEnvironmentBeforeFile(): void {
        putenv('AWS_ACCESS_KEY_ID=ENVKEY');
        putenv('AWS_SECRET_ACCESS_KEY=ENVSECRET');

        file_put_contents($this->tmpCredFile, "[default]\naws_access_key_id=FILEKEY\naws_secret_access_key=FILESECRET\n");

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
        };

        $creds = $chain->resolve();

        // Environment should win
        $this->assertEquals('ENVKEY', $creds['access_key']);
    }

    public function testResolveFileWhenNoEnvironment(): void {
        // No env vars set

        file_put_contents($this->tmpCredFile, "[default]\naws_access_key_id=FILEKEY\naws_secret_access_key=FILESECRET\n");

        $chain = new class($this->tmpCredFile) extends AwsCredentialChain {
            public function __construct(private string $path) {}
            public function getCredentialsFilePath(): string { return $this->path; }
            // Override metadata to avoid real network call
            public function fromMetadataService(): ?array { return null; }
        };

        $creds = $chain->resolve();

        $this->assertEquals('FILEKEY', $creds['access_key']);
    }

    public function testResolveReturnsNullWhenNothingFound(): void {
        $chain = new class() extends AwsCredentialChain {
            public function getCredentialsFilePath(): string { return '/nonexistent'; }
            public function fromMetadataService(): ?array { return null; }
        };

        $this->assertNull($chain->resolve());
    }

    // =========================================================================
    // getCredentialsFilePath()
    // =========================================================================

    public function testCredentialsFilePathContainsAwsDir(): void {
        $chain = new AwsCredentialChain();
        $path  = $chain->getCredentialsFilePath();

        $this->assertStringContainsString('.aws', $path);
        $this->assertStringContainsString('credentials', $path);
    }
}
