<?php

namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Exception\AuthenticationException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;

/**
 * Tests for Google Application Default Credentials (ADC) support.
 */
class GoogleAdcTest extends TestCase {
    private array $savedEnv = [];
    private array $tmpFiles = [];

    protected function setUp(): void {
        foreach (['GOOGLE_APPLICATION_CREDENTIALS'] as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void {
        foreach ($this->savedEnv as $var => $value) {
            if ($value === false) {
                putenv($var);
            } else {
                putenv("{$var}={$value}");
            }
        }

        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    // =========================================================================
    // Construction without credentials
    // =========================================================================

    public function testConstructionWithoutCredentialsSucceeds(): void {
        // ADC will be tried at request time — construction should not throw
        $client = new GoogleClient(new GoogleClientConfig(model: 'gemini-2.5-flash'));

        $this->assertInstanceOf(GoogleClient::class, $client);
    }

    public function testVertexAiConstructionWithoutCredentialsSucceeds(): void {
        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            projectId: 'my-project',
            api: GoogleApi::VERTEX_AI,
        ));
        $this->assertInstanceOf(GoogleClient::class, $client);
    }

    // =========================================================================
    // GOOGLE_APPLICATION_CREDENTIALS env var
    // =========================================================================

    public function testAdcUsesGoogleApplicationCredentialsEnvVar(): void {
        // Create a fake service account key file
        $keyFile = $this->createFakeServiceAccountFile();
        putenv("GOOGLE_APPLICATION_CREDENTIALS={$keyFile}");

        $fakeHttp = new FakeHttpClient();

        // Token exchange response
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'access_token' => 'adc-env-token',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ])));

        // Chat response
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [['text' => 'Hello']]],
                'finishReason' => 'STOP',
            ]],
        ])));

        $client = new GoogleClient(new GoogleClientConfig(model: 'gemini-2.5-flash'));
        $client->setHttpClient($fakeHttp);

        $response = $client->chat([new \WebFiori\Ai\Message('user', 'Hi')]);

        $this->assertEquals('Hello', $response->getMessage()->getContent());

        // Verify Authorization header used the ADC token
        $chatRequest = $fakeHttp->getLastRequest();
        $this->assertEquals('Bearer adc-env-token', $chatRequest->getHeaders()['Authorization']);
    }

    public function testAdcIgnoresEnvVarIfFileDoesNotExist(): void {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=/nonexistent/key.json');

        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            accessToken: 'explicit-token', // Falls back to explicit
        ));

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [['text' => 'OK']]],
                'finishReason' => 'STOP',
            ]],
        ])));

        $client->setHttpClient($fakeHttp);
        $client->chat([new \WebFiori\Ai\Message('user', 'Hi')]);

        $request = $fakeHttp->getLastRequest();
        $this->assertEquals('Bearer explicit-token', $request->getHeaders()['Authorization']);
    }

    // =========================================================================
    // Explicit credentials still work (priority order)
    // =========================================================================

    public function testExplicitAccessTokenTakesPriorityOverAdc(): void {
        // Even with env var set, explicit access_token wins
        $keyFile = $this->createFakeServiceAccountFile();
        putenv("GOOGLE_APPLICATION_CREDENTIALS={$keyFile}");

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [['text' => 'OK']]],
                'finishReason' => 'STOP',
            ]],
        ])));

        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            accessToken: 'explicit-priority-token',
        ));
        $client->setHttpClient($fakeHttp);
        $client->chat([new \WebFiori\Ai\Message('user', 'Hi')]);

        $request = $fakeHttp->getLastRequest();
        // Explicit token used, no token exchange happened
        $this->assertEquals('Bearer explicit-priority-token', $request->getHeaders()['Authorization']);
        // Only one request made (the chat), no token exchange
        $this->assertStringContainsString('generateContent', $request->getUrl());
    }

    public function testExplicitCredentialsFileTakesPriorityOverEnvVar(): void {
        // Set env var
        $envKeyFile = $this->createFakeServiceAccountFile('env-email@project.iam.gserviceaccount.com');
        putenv("GOOGLE_APPLICATION_CREDENTIALS={$envKeyFile}");

        // Explicit credentials file (different email)
        $explicitKeyFile = $this->createFakeServiceAccountFile('explicit@project.iam.gserviceaccount.com');

        $fakeHttp = new FakeHttpClient();
        // Token exchange for explicit credentials
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'access_token' => 'explicit-creds-token',
            'expires_in'   => 3600,
        ])));
        // Chat response
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [['text' => 'OK']]],
                'finishReason' => 'STOP',
            ]],
        ])));

        $client = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            credentials: $explicitKeyFile, // Explicit wins
        ));
        $client->setHttpClient($fakeHttp);
        $client->chat([new \WebFiori\Ai\Message('user', 'Hi')]);

        $request = $fakeHttp->getLastRequest();
        $this->assertEquals('Bearer explicit-creds-token', $request->getHeaders()['Authorization']);
    }

    // =========================================================================
    // No credentials at all — should throw at request time
    // =========================================================================

    public function testThrowsWhenNoCredentialsAvailable(): void {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/No Google credentials found/');

        $client = new GoogleClient(new GoogleClientConfig(model: 'gemini-2.5-flash'));

        // Mock: override ADC methods to return nothing
        $fakeHttp = new FakeHttpClient();
        $client->setHttpClient($fakeHttp);

        // Use a subclass to short-circuit all ADC paths
        // Instead, simulate by pointing env var at nonexistent file and having no credentials
        // This will fall through to metadata server which is unavailable in test
        // The test relies on no real ADC being configured in the test environment
        // If real ADC is configured, this test is skipped
        if (!empty(getenv('GOOGLE_APPLICATION_CREDENTIALS')) || file_exists($this->getGcloudPath())) {
            $this->markTestSkipped('Real ADC credentials found in environment — skipping negative test.');
        }

        $client->chat([new \WebFiori\Ai\Message('user', 'Hi')]);
    }

    // =========================================================================
    // gcloud authorized_user token refresh
    // =========================================================================

    public function testGcloudAuthorizedUserCredentialsAreRefreshed(): void {
        $gcloudCreds = json_encode([
            'type'          => 'authorized_user',
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ]);

        $tmpGcloud = sys_get_temp_dir() . '/gcloud_adc_test_' . uniqid() . '.json';
        file_put_contents($tmpGcloud, $gcloudCreds);
        $this->tmpFiles[] = $tmpGcloud;

        $fakeHttp = new FakeHttpClient();

        // Token refresh response
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'access_token' => 'gcloud-refreshed-token',
            'expires_in'   => 3600,
        ])));

        // Chat response
        $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [['text' => 'Hello']]],
                'finishReason' => 'STOP',
            ]],
        ])));

        // Use a subclass that points to our test gcloud file
        // Anonymous class approach removed — constructor now requires typed config.
        // Test via env var path instead.
        putenv("GOOGLE_APPLICATION_CREDENTIALS={$tmpGcloud}");

        $client2 = new GoogleClient(new GoogleClientConfig(model: 'gemini-2.5-flash'));
        $client2->setHttpClient($fakeHttp);

        $response = $client2->chat([new \WebFiori\Ai\Message('user', 'Hi')]);

        $this->assertEquals('Hello', $response->getMessage()->getContent());

        $chatRequest = $fakeHttp->getLastRequest();
        $this->assertEquals('Bearer gcloud-refreshed-token', $chatRequest->getHeaders()['Authorization']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createFakeServiceAccountFile(string $email = 'test@project.iam.gserviceaccount.com'): string {
        // Generate a real RSA key for signing
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        $keyData = json_encode([
            'type'                        => 'service_account',
            'project_id'                  => 'test-project',
            'client_email'                => $email,
            'private_key'                 => $privateKeyPem,
            'token_uri'                   => 'https://oauth2.googleapis.com/token',
        ]);

        $file = sys_get_temp_dir() . '/fake_sa_' . uniqid() . '.json';
        file_put_contents($file, $keyData);
        $this->tmpFiles[] = $file;

        return $file;
    }

    private function getGcloudPath(): string {
        if (PHP_OS_FAMILY === 'Windows') {
            return (getenv('APPDATA') ?: '') . DIRECTORY_SEPARATOR . 'gcloud' . DIRECTORY_SEPARATOR . 'application_default_credentials.json';
        }

        return (getenv('HOME') ?: '') . '/.config/gcloud/application_default_credentials.json';
    }
}
