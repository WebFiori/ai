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
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\Recording\FixtureCatalog;
use WebFiori\Ai\Http\Recording\FixtureNotFoundException;
use WebFiori\Ai\Http\Recording\FullBodyFingerprintStrategy;
use WebFiori\Ai\Http\Recording\HttpFixture;
use WebFiori\Ai\Http\Recording\MessagesFingerprintStrategy;
use WebFiori\Ai\Http\Recording\RecordingHttpClient;
use WebFiori\Ai\Http\Recording\ReplayHttpClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * Tests for #34: Response Recording & Replay.
 */
class RecordingReplayTest extends TestCase {
    private string $tmpDir;

    protected function setUp(): void {
        $this->tmpDir = sys_get_temp_dir().'/ai_fixtures_'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->tmpDir.'/*.json') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    // =========================================================================
    // FingerprintStrategyInterface implementations
    // =========================================================================

    public function testMessagesFingerprintUsesMessagesKey(): void {
        $strategy = new MessagesFingerprintStrategy();

        $req1 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
        $req2 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']], 'temperature' => 0.9]);

        // Same messages, different temperature → same fingerprint
        $this->assertEquals($strategy->fingerprint($req1), $strategy->fingerprint($req2));
    }

    public function testMessagesFingerprintDiffersForDifferentMessages(): void {
        $strategy = new MessagesFingerprintStrategy();

        $req1 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
        $req2 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hello']]]);

        $this->assertNotEquals($strategy->fingerprint($req1), $strategy->fingerprint($req2));
    }

    public function testMessagesFingerprintUsesContentsForGoogle(): void {
        $strategy = new MessagesFingerprintStrategy();

        $req1 = $this->makeRequest(['contents' => [['role' => 'user', 'parts' => [['text' => 'Hi']]]]]);
        $req2 = $this->makeRequest(['contents' => [['role' => 'user', 'parts' => [['text' => 'Hi']]]], 'generationConfig' => ['temperature' => 0.5]]);

        $this->assertEquals($strategy->fingerprint($req1), $strategy->fingerprint($req2));
    }

    public function testMessagesFingerprintUsesInputForInteractions(): void {
        $strategy = new MessagesFingerprintStrategy();
        $req = $this->makeRequest(['input' => [['type' => 'user_input', 'content' => [['text' => 'Hi']]]]]);

        $fp = $strategy->fingerprint($req);
        $this->assertNotEmpty($fp);
    }

    public function testMessagesFingerprintDiffersForDifferentUrls(): void {
        $strategy = new MessagesFingerprintStrategy();

        $req1 = new HttpRequest('POST', 'https://api.openai.com/v1/chat/completions', [], json_encode(['messages' => []]));
        $req2 = new HttpRequest('POST', 'https://api.anthropic.com/v1/messages', [], json_encode(['messages' => []]));

        $this->assertNotEquals($strategy->fingerprint($req1), $strategy->fingerprint($req2));
    }

    public function testFullBodyFingerprintIncludesAllFields(): void {
        $strategy = new FullBodyFingerprintStrategy();

        $req1 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
        $req2 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']], 'temperature' => 0.9]);

        // Different temperature → different fingerprint
        $this->assertNotEquals($strategy->fingerprint($req1), $strategy->fingerprint($req2));
    }

    public function testFullBodyFingerprintSameForIdenticalBodies(): void {
        $strategy = new FullBodyFingerprintStrategy();

        $req1 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
        $req2 = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        $this->assertEquals($strategy->fingerprint($req1), $strategy->fingerprint($req2));
    }

    // =========================================================================
    // HttpFixture
    // =========================================================================

    public function testHttpFixtureNonStreaming(): void {
        $response = new HttpResponse(200, ['Content-Type' => 'application/json'], '{"id":"1"}');
        $fixture = new HttpFixture('fp123', $response, 'test_fixture');

        $this->assertEquals('fp123', $fixture->getFingerprint());
        $this->assertEquals('test_fixture', $fixture->getName());
        $this->assertFalse($fixture->isStreaming());
        $this->assertSame($response, $fixture->getResponse());
        $this->assertEmpty($fixture->getChunks());
        $this->assertNotEmpty($fixture->getRecordedAt());
    }

    public function testHttpFixtureStreaming(): void {
        $chunks = ["data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n", "data: [DONE]\n\n"];
        $fixture = HttpFixture::streaming('fp456', $chunks, 'streaming_test');

        $this->assertEquals('fp456', $fixture->getFingerprint());
        $this->assertTrue($fixture->isStreaming());
        $this->assertEquals($chunks, $fixture->getChunks());
        $this->assertNull($fixture->getResponse());
    }

    public function testHttpFixtureToArrayAndFromArray(): void {
        $response = new HttpResponse(200, [], json_encode(['id' => '1', 'model' => 'gpt-4o']));
        $fixture = new HttpFixture('fp789', $response, 'roundtrip_test', '2026-08-23T00:00:00+00:00');

        $array = $fixture->toArray();
        $restored = HttpFixture::fromArray($array);

        $this->assertEquals($fixture->getFingerprint(), $restored->getFingerprint());
        $this->assertEquals($fixture->getName(), $restored->getName());
        $this->assertEquals($fixture->getRecordedAt(), $restored->getRecordedAt());
        $this->assertFalse($restored->isStreaming());
        $this->assertEquals(200, $restored->getResponse()->getStatusCode());
    }

    public function testHttpFixtureStreamingToArrayAndFromArray(): void {
        $chunks = ["data: {\"text\":\"Hello\"}\n\n", "data: [DONE]\n\n"];
        $fixture = HttpFixture::streaming('fpstream', $chunks, 'stream_test');

        $array = $fixture->toArray();
        $restored = HttpFixture::fromArray($array);

        $this->assertTrue($restored->isStreaming());
        $this->assertEquals($chunks, $restored->getChunks());
    }

    public function testHttpFixtureFromArrayThrowsWhenMissingFingerprint(): void {
        $this->expectException(\InvalidArgumentException::class);
        HttpFixture::fromArray(['name' => 'test', 'streaming' => false]);
    }

    public function testHttpFixtureFromArrayWithStringBody(): void {
        $fixture = HttpFixture::fromArray([
            'fingerprint' => 'fp_str',
            'streaming' => false,
            'response' => ['status' => 200, 'headers' => [], 'body' => 'plain text'],
        ]);

        $this->assertEquals(200, $fixture->getResponse()->getStatusCode());
        $this->assertEquals('plain text', $fixture->getResponse()->getBody());
    }

    // =========================================================================
    // FixtureCatalog
    // =========================================================================

    public function testFixtureCatalogThrowsForNonExistentPath(): void {
        $this->expectException(\InvalidArgumentException::class);
        new FixtureCatalog('/nonexistent/path/abc');
    }

    public function testFixtureCatalogSaveAndFind(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $response = new HttpResponse(200, [], '{"id":"1"}');
        $fixture = new HttpFixture('fp_save', $response, 'save_test');

        $catalog->save($fixture);

        $found = $catalog->find('fp_save');
        $this->assertNotNull($found);
        $this->assertEquals('fp_save', $found->getFingerprint());
    }

    public function testFixtureCatalogFindReturnsNullForMiss(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $this->assertNull($catalog->find('nonexistent_fp'));
    }

    public function testFixtureCatalogCountsFixtures(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $this->assertEquals(0, $catalog->count());

        $catalog->save(new HttpFixture('fp1', new HttpResponse(200, [], '{}'), 'test1'));
        $catalog->save(new HttpFixture('fp2', new HttpResponse(200, [], '{}'), 'test2'));

        $this->assertEquals(2, $catalog->count());
    }

    public function testFixtureCatalogLoadsExistingFiles(): void {
        // Save a fixture to disk directly
        $fixture = new HttpFixture('fp_load', new HttpResponse(200, [], '{"model":"gpt-4o"}'), 'load_test');
        $json = json_encode($fixture->toArray(), JSON_PRETTY_PRINT);
        file_put_contents($this->tmpDir.'/test_fixture.json', $json);

        // New catalog should load it
        $catalog = new FixtureCatalog($this->tmpDir);
        $found = $catalog->find('fp_load');

        $this->assertNotNull($found);
        $this->assertEquals('load_test', $found->getName());
    }

    public function testFixtureCatalogSkipsMalformedFiles(): void {
        file_put_contents($this->tmpDir.'/bad.json', 'not valid json');
        file_put_contents($this->tmpDir.'/missing_fp.json', json_encode(['name' => 'test']));

        $catalog = new FixtureCatalog($this->tmpDir);
        $this->assertEquals(0, $catalog->count()); // no exceptions thrown
    }

    public function testFixtureCatalogFilenameFromName(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $fixture = new HttpFixture('fp_name_test', new HttpResponse(200, [], '{}'), 'Ask About PHP');
        $catalog->save($fixture);

        $files = glob($this->tmpDir.'/*.json');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('ask_about_php', basename($files[0]));
    }

    public function testFixtureCatalogFilenameWithoutName(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $fixture = new HttpFixture('fp_noname', new HttpResponse(200, [], '{}'));
        $catalog->save($fixture);

        $files = glob($this->tmpDir.'/*.json');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('fixture_', basename($files[0]));
    }

    public function testFixtureCatalogOverwritesOnSameFingerprint(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $catalog->save(new HttpFixture('fp_overwrite', new HttpResponse(200, [], '{"v":1}'), 'v1'));
        $catalog->save(new HttpFixture('fp_overwrite', new HttpResponse(200, [], '{"v":2}'), 'v2'));

        $this->assertEquals(1, count(glob($this->tmpDir.'/*.json') ?: []));
    }

    public function testFixtureCatalogGetAll(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $catalog->save(new HttpFixture('fp_a', new HttpResponse(200, [], '{}'), 'a'));
        $catalog->save(new HttpFixture('fp_b', new HttpResponse(200, [], '{}'), 'b'));

        $all = $catalog->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('fp_a', $all);
        $this->assertArrayHasKey('fp_b', $all);
    }

    public function testFixtureCatalogGetPath(): void {
        $catalog = new FixtureCatalog($this->tmpDir);
        $this->assertEquals($this->tmpDir, $catalog->getPath());
    }

    // =========================================================================
    // RecordingHttpClient
    // =========================================================================

    public function testRecordingClientSavesNonStreamingResponse(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, [], json_encode(['id' => '1', 'model' => 'gpt-4o', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']]])));

        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        $response = $recorder->send($req);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $recorder->getCatalog()->count());
    }

    public function testRecordingClientSavesStreamingChunks(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addStreamingChunks([
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        $received = [];
        $recorder->sendStreaming($req, function ($chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertCount(2, $received);
        $this->assertEquals(1, $recorder->getCatalog()->count());

        // Verify fixture is streaming
        $strategy = new MessagesFingerprintStrategy();
        $fp = $strategy->fingerprint($req);
        $fixture = $recorder->getCatalog()->find($fp);
        $this->assertNotNull($fixture);
        $this->assertTrue($fixture->isStreaming());
        $this->assertCount(2, $fixture->getChunks());
    }

    public function testRecordingClientUsesCustomFingerprint(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, [], '{}'));

        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir, new FullBodyFingerprintStrategy());
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hi']], 'temperature' => 0.5]);

        $recorder->send($req);

        $fp = (new FullBodyFingerprintStrategy())->fingerprint($req);
        $this->assertNotNull($recorder->getCatalog()->find($fp));
    }

    public function testRecordingClientGetCatalog(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);

        $this->assertInstanceOf(FixtureCatalog::class, $recorder->getCatalog());
    }

    public function testRecordingClientBuildsProviderNameFromUrl(): void {
        $cases = [
            ['https://api.openai.com/v1/chat/completions', 'openai'],
            ['https://api.anthropic.com/v1/messages', 'anthropic'],
            ['https://generativelanguage.googleapis.com/v1beta/models/test:generateContent', 'google'],
            ['https://us-central1-aiplatform.googleapis.com/v1/projects/test/models/test:predict', 'vertex'],
            ['https://bedrock-runtime.us-east-1.amazonaws.com/model/test/converse', 'bedrock'],
        ];

        foreach ($cases as [$url, $expectedProvider]) {
            $subDir = $this->tmpDir.'/'.$expectedProvider;
            mkdir($subDir, 0777, true);

            $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
            $fakeInner->addResponse(new HttpResponse(200, [], '{}'));
            $recorder = new RecordingHttpClient($fakeInner, $subDir);

            $req = new HttpRequest('POST', $url, [], json_encode(['messages' => [['role' => 'user', 'content' => 'hi']]]));
            $recorder->send($req);

            $files = glob($subDir.'/'.$expectedProvider.'*.json');
            $this->assertNotEmpty($files, "Expected file starting with '{$expectedProvider}' for URL: {$url}");

            // Cleanup
            foreach (glob($subDir.'/*.json') ?: [] as $f) { unlink($f); }
            rmdir($subDir);
        }
    }

    public function testRecordingClientUnknownHostUsesHostname(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, [], '{}'));
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);

        $req = new HttpRequest('POST', 'https://custom.example.com/v1/chat', [], json_encode(['messages' => [['role' => 'user', 'content' => 'custom host']]]));
        $recorder->send($req);

        // Dots in hostname are converted to underscores in filename
        $files = glob($this->tmpDir.'/custom_example_com*.json');
        $this->assertNotEmpty($files, 'Expected file with hostname for unknown provider');
    }

    public function testRecordingClientAddScrubHeaders(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        // Response with sensitive header
        $fakeInner->addResponse(new HttpResponse(200, ['x-custom-secret' => 'my-secret', 'Content-Type' => 'application/json'], '{}'));
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $recorder->addScrubHeaders(['x-custom-secret']);

        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'test scrub']]]);
        $response = $recorder->send($req);

        // Original response returned unchanged
        $this->assertEquals('my-secret', $response->getHeaders()['x-custom-secret'] ?? null);

        // Fixture has redacted header
        $strategy = new MessagesFingerprintStrategy();
        $fp = $strategy->fingerprint($req);
        $fixture = $recorder->getCatalog()->find($fp);
        $this->assertNotNull($fixture);
        $savedData = $fixture->toArray();
        $this->assertEquals('[REDACTED]', $savedData['response']['headers']['x-custom-secret'] ?? null);
    }

    public function testRecordingClientScrubsDefaultAuthHeaders(): void {
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, ['authorization' => 'Bearer sk-secret', 'x-api-key' => 'key123'], '{}'));
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);

        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'auth scrub test']]]);
        $recorder->send($req);

        $strategy = new MessagesFingerprintStrategy();
        $fp = $strategy->fingerprint($req);
        $fixture = $recorder->getCatalog()->find($fp);
        $savedData = $fixture->toArray();
        $this->assertEquals('[REDACTED]', $savedData['response']['headers']['authorization'] ?? null);
        $this->assertEquals('[REDACTED]', $savedData['response']['headers']['x-api-key'] ?? null);
    }

    public function testFixtureCatalogSaveThrowsOnWriteFailure(): void {
        // Make directory read-only
        $readOnlyDir = $this->tmpDir.'/readonly';
        mkdir($readOnlyDir);
        chmod($readOnlyDir, 0555);

        try {
            $catalog = new FixtureCatalog($readOnlyDir);
            $this->expectException(\RuntimeException::class);
            $catalog->save(new HttpFixture('fp', new HttpResponse(200, [], '{}'), 'test'));
        } finally {
            chmod($readOnlyDir, 0755);
            rmdir($readOnlyDir);
        }
    }

    // =========================================================================
    // ReplayHttpClient
    // =========================================================================

    public function testReplayClientReplaysNonStreamingResponse(): void {
        // Record first
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, [], '{"id":"1","model":"gpt-4o","choices":[{"message":{"role":"assistant","content":"Hi"},"finish_reason":"stop"}]}'));
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Hello']]]);
        $recorder->send($req);

        // Replay
        $replayer = new ReplayHttpClient($this->tmpDir);
        $response = $replayer->send($req);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('gpt-4o', $response->getBody());
    }

    public function testReplayClientReplaysStreamingChunks(): void {
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n",
            "data: [DONE]\n\n",
        ];

        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addStreamingChunks($chunks);
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Stream']]]);
        $recorder->sendStreaming($req, fn($c) => null);

        // Replay streaming
        $replayer = new ReplayHttpClient($this->tmpDir);
        $received = [];
        $replayer->sendStreaming($req, function ($chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertEquals($chunks, $received);
    }

    public function testReplayClientThrowsOnMiss(): void {
        $replayer = new ReplayHttpClient($this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Unknown']]]);

        $this->expectException(FixtureNotFoundException::class);
        $replayer->send($req);
    }

    public function testFixtureNotFoundExceptionMessageIsDescriptive(): void {
        $replayer = new ReplayHttpClient($this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Unknown']]]);

        try {
            $replayer->send($req);
            $this->fail('Expected exception');
        } catch (FixtureNotFoundException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('POST', $msg);
            $this->assertStringContainsString('api.openai.com', $msg);
            $this->assertStringContainsString($this->tmpDir, $msg);
            $this->assertStringContainsString('RecordingHttpClient', $msg);
            $this->assertStringContainsString('0 fixtures', $msg);
        }
    }

    public function testReplayClientThrowsOnStreamingMissForSend(): void {
        // Create a streaming fixture
        $chunks = ["data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"];
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addStreamingChunks($chunks);
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Stream only']]]);
        $recorder->sendStreaming($req, fn($c) => null);

        // Try to use send() with a streaming fixture
        $replayer = new ReplayHttpClient($this->tmpDir);
        $this->expectException(\LogicException::class);
        $replayer->send($req);
    }

    public function testReplayNonStreamingFixtureInStreamingContext(): void {
        // Record non-streaming
        $body = '{"choices":[{"message":{"role":"assistant","content":"Hello"},"finish_reason":"stop"}]}';
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, [], $body));
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);
        $req = $this->makeRequest(['messages' => [['role' => 'user', 'content' => 'Non-stream']]]);
        $recorder->send($req);

        // Use in streaming context — should replay body as single chunk
        $replayer = new ReplayHttpClient($this->tmpDir);
        $received = [];
        $replayer->sendStreaming($req, function ($chunk) use (&$received) {
            $received[] = $chunk;
        });

        $this->assertCount(1, $received);
        $this->assertStringContainsString('Hello', $received[0]);
    }

    public function testReplayClientGetCatalog(): void {
        $replayer = new ReplayHttpClient($this->tmpDir);
        $this->assertInstanceOf(FixtureCatalog::class, $replayer->getCatalog());
    }

    // =========================================================================
    // End-to-end: record then replay with real provider client
    // =========================================================================

    public function testEndToEndRecordAndReplay(): void {
        $openAIResponse = [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'PHP is a scripting language.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 6, 'total_tokens' => 16],
        ];

        // --- Recording phase ---
        $fakeInner = new \WebFiori\Ai\Http\FakeHttpClient();
        $fakeInner->addResponse(new HttpResponse(200, [], json_encode($openAIResponse)));
        $recorder = new RecordingHttpClient($fakeInner, $this->tmpDir);

        $client = new OpenAIClient(new OpenAIClientConfig(apiKey: 'test-key', model: 'gpt-4o'));
        $client->setHttpClient($recorder);

        $messages = [new \WebFiori\Ai\Message('user', 'What is PHP?')];
        $recorded = $client->chat($messages);

        $this->assertEquals('PHP is a scripting language.', $recorded->getMessage()->getContent());

        // --- Replay phase (no real API key needed) ---
        $replayer = new ReplayHttpClient($this->tmpDir);
        $client2 = new OpenAIClient(new OpenAIClientConfig(apiKey: 'FAKE_KEY', model: 'gpt-4o'));
        $client2->setHttpClient($replayer);

        $replayed = $client2->chat($messages);

        $this->assertEquals('PHP is a scripting language.', $replayed->getMessage()->getContent());
        $this->assertEquals($recorded->getModel(), $replayed->getModel());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeRequest(array $body, string $url = 'https://api.openai.com/v1/chat/completions'): HttpRequest {
        return new HttpRequest('POST', $url, ['Content-Type' => 'application/json'], json_encode($body));
    }
}
