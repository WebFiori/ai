<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Http\Recording;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Http\Recording\RecordingHttpClient;
use WebFiori\Ai\Http\Recording\ReplayHttpClient;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;

/**
 * End-to-end record → replay integration tests.
 *
 * A FakeHttpClient stands in for the real network during the recording phase
 * (so no API key is needed). The recorded fixtures are written to a temp
 * directory, then replayed through a fresh provider via ReplayHttpClient —
 * driving the real request-building and response-parsing code paths.
 *
 * This exercises RecordingHttpClient (scrub + save), FixtureCatalog
 * (save/load/find), and ReplayHttpClient without any live calls.
 */
class RecordReplayRoundTripTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir().'/wf_fixtures_'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private function config(): OpenAIClientConfig {
        return new OpenAIClientConfig(apiKey: 'sk-test', model: 'gpt-4o');
    }

    private function chatResponseBody(string $text): string {
        return json_encode([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 4, 'total_tokens' => 13],
        ]);
    }

    // =========================================================================
    // Non-streaming chat round trip
    // =========================================================================

    public function testChat_RecordThenReplay(): void {
        $messages = [new Message('user', 'What is PHP?')];

        // --- Record phase: fake network, real recorder ---
        $fake = new FakeHttpClient();
        $fake->addResponse(new HttpResponse(200, [
            'Authorization' => 'Bearer sk-secret',
            'Content-Type' => 'application/json',
        ], $this->chatResponseBody('PHP is a scripting language.')));

        $recorder = new RecordingHttpClient($fake, $this->dir);

        $recording = new OpenAIClient($this->config());
        $recording->setHttpClient($recorder);
        $recorded = $recording->chat($messages);

        $this->assertSame('PHP is a scripting language.', $recorded->getMessage()->getContent());
        // A fixture file must now exist on disk.
        $this->assertNotEmpty(glob($this->dir.'/*.json'));

        // Sensitive header must have been scrubbed in the saved fixture.
        $saved = json_decode(file_get_contents(glob($this->dir.'/*.json')[0]), true);
        $this->assertSame('[REDACTED]', $saved['response']['headers']['Authorization']);

        // --- Replay phase: fresh provider, no network ---
        $replayer = new ReplayHttpClient($this->dir);
        $replay = new OpenAIClient($this->config());
        $replay->setHttpClient($replayer);

        $replayed = $replay->chat($messages);

        $this->assertSame('PHP is a scripting language.', $replayed->getMessage()->getContent());
        $this->assertSame('stop', $replayed->getFinishReason());
        $this->assertSame(9, $replayed->getUsage()->getPromptTokens());
        $this->assertSame(1, $replayer->getCatalog()->count());
    }

    // =========================================================================
    // Multi-modal request formatting (document part) via replay
    // =========================================================================

    public function testChat_MultiModalDocumentPart_RecordThenReplay(): void {
        $messages = [
            new Message('user', [
                ContentPart::text('Summarize this file.'),
                ContentPart::document(base64_encode('plain text file body'), 'text/plain'),
            ]),
        ];

        $fake = new FakeHttpClient();
        $fake->addResponse(new HttpResponse(200, [], $this->chatResponseBody('Summary here.')));

        $recorder = new RecordingHttpClient($fake, $this->dir);
        $recording = new OpenAIClient($this->config());
        $recording->setHttpClient($recorder);
        $recording->chat($messages);

        // The recorded request body carries the formatted multi-modal content:
        // a text part and a "file" part (text/plain -> file, not image).
        $requestBody = json_decode($fake->getLastRequest()->getBody(), true);
        $content = $requestBody['messages'][0]['content'];
        $types = array_column($content, 'type');
        $this->assertContains('text', $types);
        $this->assertContains('file', $types);

        // Replay resolves the same fingerprint.
        $replayer = new ReplayHttpClient($this->dir);
        $replay = new OpenAIClient($this->config());
        $replay->setHttpClient($replayer);
        $response = $replay->chat($messages);

        $this->assertSame('Summary here.', $response->getMessage()->getContent());
    }

    // =========================================================================
    // Streaming round trip
    // =========================================================================

    public function testStreaming_RecordThenReplay(): void {
        $messages = [new Message('user', 'Stream hello')];

        $chunks = [
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hel']]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'lo']]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n",
            "data: [DONE]\n\n",
        ];

        $fake = new FakeHttpClient();
        $fake->addStreamingChunks($chunks);

        $recorder = new RecordingHttpClient($fake, $this->dir);
        $recording = new OpenAIClient($this->config());
        $recording->setHttpClient($recorder);

        $recordedText = '';
        $recording->streamChat($messages, function (string $t) use (&$recordedText): void {
            $recordedText .= $t;
        });
        $this->assertSame('Hello', $recordedText);

        // Replay the recorded streaming fixture through a fresh provider.
        $replayer = new ReplayHttpClient($this->dir);
        $replay = new OpenAIClient($this->config());
        $replay->setHttpClient($replayer);

        $replayedText = '';
        $replay->streamChat($messages, function (string $t) use (&$replayedText): void {
            $replayedText .= $t;
        });

        $this->assertSame('Hello', $replayedText);
    }
}
