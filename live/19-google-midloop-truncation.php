<?php

/**
 * Live Test 19: Mid-loop context truncation with Google (issue #206)
 *
 * Runs an auto-executed tool loop against real Gemini models with a small
 * sliding window and ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS. The tool
 * returns large results, so the loop truncates between iterations.
 *
 * After a truncation the next request must contain only the truncated
 * conversation. GoogleClient::buildIncrementalChatRequest() appends it to the
 * previous request instead, so the original question is sent twice and the
 * request (and the model's reported prompt tokens) grows.
 *
 * The test records every outgoing request and the prompt token count the model
 * reports for it. It fails while the bug exists. If the model does not call the
 * tool often enough to trigger truncation, it prints a warning and passes.
 *
 * Usage:
 *   source keys/env.sh && php live/19-google-midloop-truncation.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Http\CurlHttpClient;
use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Status;
use WebFiori\Ai\Tool\Tool;

section('Mid-loop truncation with Google (#206)');

const MIDLOOP_QUESTION = 'Audit the documents: read document 1, then 2, then 3, then 4, then 5, calling read_document once per message, one at a time. After reading all five, reply with the word DONE.';

/**
 * Passes requests through to a real client and records what was sent.
 */
final class RecordingHttpClient implements HttpClientInterface {
    /** @var array<int, array{bytes: int, question_count: int, prompt_tokens: int|null}> */
    public array $requests = [];

    public function __construct(private HttpClientInterface $inner) {
    }

    public function send(HttpRequest $request): HttpResponse {
        $response = $this->inner->send($request);

        // Skip the OAuth token exchange; only model calls are of interest.
        if (!str_contains($request->getUrl(), 'generateContent') && !str_contains($request->getUrl(), 'interactions')) {
            return $response;
        }

        $data = json_decode($response->getBody(), true) ?? [];

        $this->requests[] = [
            'bytes' => strlen($request->getBody()),
            'question_count' => substr_count($request->getBody(), 'Audit the documents'),
            'prompt_tokens' => $data['usageMetadata']['promptTokenCount']
                ?? $data['usage']['total_input_tokens']
                ?? $data['usage']['input_tokens']
                ?? null,
        ];

        return $response;
    }

    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $this->inner->sendStreaming($request, $onChunk);
    }
}

function midLoopProbe(string $name, callable $factory): void {
    run("{$name}: truncated tool loop sends each message once", function () use ($factory, $name)
    {
        /** @var GoogleClient $client */
        $client = $factory();
        $recorder = new RecordingHttpClient(new CurlHttpClient());
        $truncations = 0;

        $client->setHttpClient($recorder);
        $client->setContextWindowStrategy(new SlidingWindowStrategy(maxTokens: 2500, reserveForCompletion: 200));
        $client->setStatusEmitter(new CallbackStatusEmitter(function (string $status, array $context) use (&$truncations): void
        {
            if ($status === Status::TRUNCATING_CONTEXT && isset($context['iteration'])) {
                $truncations++;
            }
        }));

        $tool = new Tool(
            'read_document',
            'Reads one document by number and returns its text.',
            [
                'type' => 'object',
                'properties' => ['n' => ['type' => 'integer', 'description' => 'Document number, 1 to 5']],
                'required' => ['n'],
            ],
            fn (array $args): string => 'document-'.($args['n'] ?? 0).' '.str_repeat('The quick brown fox jumps over the lazy dog. ', 140)
        );

        $client->chat(
            [new Message('system', 'You are an auditor. Use the tool as instructed.'), new Message('user', MIDLOOP_QUESTION)],
            [
                ChatOption::TOOLS => [$tool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
                ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
                ChatOption::MAX_TOOL_ITERATIONS => 8,
            ]
        );

        echo "    → requests sent: ".count($recorder->requests).", mid-loop truncations: {$truncations}\n";

        foreach ($recorder->requests as $i => $r) {
            echo sprintf(
                "    → request %d: %6d bytes, question sent %dx, model prompt tokens: %s\n",
                $i,
                $r['bytes'],
                $r['question_count'],
                $r['prompt_tokens'] ?? 'n/a'
            );
        }

        if ($truncations === 0) {
            warn("{$name}: the model did not call the tool often enough to trigger truncation (inconclusive).");

            return true;
        }

        foreach ($recorder->requests as $i => $r) {
            if ($r['question_count'] !== 1) {
                throw new RuntimeException("Request {$i} sent the original question {$r['question_count']} times (expected once)");
            }
        }

        return true;
    });
}

function warn(string $message): void {
    echo "\033[33m  ⚠️  WARN\033[0m  {$message}\n";
}

if (is_file(KEY_PATH)) {
    midLoopProbe('Gemini 2.x (generateContent)', 'gemini2Client');
    midLoopProbe('Gemini 3.x (Interactions)', 'gemini3Client');
} else {
    warn('GCP key file not found, skipping Gemini');
}
