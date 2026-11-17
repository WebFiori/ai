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
use ReflectionMethod;
use stdClass;
use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\Cancellation\CancellationTokenSource;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Role;
use WebFiori\Ai\Status;
use WebFiori\Ai\Tool\Tool;

/**
 * Mid-loop truncation with the real GoogleClient (issue #206).
 *
 * After the tool loop truncates the conversation, the next request must
 * contain only the truncated conversation. The loop used to hand the truncated
 * conversation to GoogleClient::buildIncrementalChatRequest(), which appends
 * to the previous, untruncated request, so messages were sent twice and the
 * request grew. The loop now builds such requests from scratch.
 *
 * The provider-independent tests in Context/MidLoopTruncationTest use a stub
 * client, so they cannot see what a real provider puts on the wire. These do.
 */
class GoogleMidLoopTruncationTest extends TestCase {
    private const QUESTION = 'Audit the documents please.';

    /**
     * The request being extended already carries the caller's token, and the
     * extended request must keep it so the transfer stays cancellable.
     */
    public function testIncrementalAppendKeepsTheCancellationToken(): void {
        foreach ([GoogleApiVersion::GENERATE_CONTENT, GoogleApiVersion::INTERACTIONS] as $version) {
            $client = $this->createClient($version);
            $token = (new CancellationTokenSource())->getToken();
            $all = [new Message(Role::SYSTEM, 'system'), new Message(Role::USER, 'question')];
            $previous = $this->build($client, $all)->withCancellationToken($token);

            $new = [new Message(Role::ASSISTANT, 'answer-1')];
            $next = $this->incremental($client, $previous, array_merge($all, $new), $new);

            $this->assertSame($token, $next->getCancellationToken(), "Token lost for {$version->name}");
        }
    }

    // =========================================================================
    // The incremental builder only appends. The tool loop never asks it to
    // handle a truncated conversation: it builds those requests from scratch.
    // =========================================================================

    /**
     * Control: appending only the new messages (no truncation) works.
     */
    public function testIncrementalAppendWithoutTruncationStillAppends(): void {
        $client = $this->createClient(GoogleApiVersion::GENERATE_CONTENT);
        $all = [
            new Message(Role::SYSTEM, 'system'),
            new Message(Role::USER, 'question'),
            new Message(Role::ASSISTANT, 'answer-1'),
        ];
        $previous = $this->build($client, $all);

        $new = [new Message(Role::USER, 'follow-up')];
        $next = $this->incremental($client, $previous, array_merge($all, $new), $new);

        $this->assertSame(['question', 'answer-1', 'follow-up'], $this->labels($this->contents($next)));
    }

    public function testIncrementalAppendWithoutTruncationStillAppendsForInteractions(): void {
        $client = $this->createClient(GoogleApiVersion::INTERACTIONS);
        $all = [
            new Message(Role::SYSTEM, 'system'),
            new Message(Role::USER, 'question'),
            new Message(Role::ASSISTANT, 'answer-1'),
        ];
        $previous = $this->build($client, $all);
        $before = count($this->input($previous));

        $new = [new Message(Role::USER, 'follow-up')];
        $next = $this->incremental($client, $previous, array_merge($all, $new), $new);

        $this->assertCount($before + 1, $this->input($next));
    }

    // =========================================================================
    // End to end: chat() with a tool loop and a sliding window
    // =========================================================================

    public function testToolLoopSendsEachMessageOnceAfterGenerateContentTruncation(): void {
        $client = $this->createClient(GoogleApiVersion::GENERATE_CONTENT);
        $http = new FakeHttpClient();

        for ($i = 1; $i <= 5; $i++) {
            $http->addResponse($this->generateContentResponse([
                'functionCall' => ['name' => 'read_document', 'args' => ['n' => $i]],
            ]));
        }
        $http->addResponse($this->generateContentResponse(['text' => 'done']));

        $this->runLoop($client, $http, $truncations);

        $this->assertNotEmpty($truncations, 'Precondition: the loop must truncate for this test to mean anything');
        $this->assertCount(6, $http->getRequests());

        foreach ($http->getRequests() as $i => $request) {
            $this->assertSame(
                1,
                substr_count($request->getBody(), self::QUESTION),
                "Request #{$i} sends the original question more than once"
            );
        }

        $this->assertStringNotContainsString(
            'document-1-',
            $http->getLastRequest()->getBody(),
            'The oldest tool result was truncated and must not be sent again'
        );
        $this->assertLessThanOrEqual(
            strlen($http->getRequests()[2]->getBody()) + 2000,
            strlen($http->getLastRequest()->getBody()),
            'The final request grew past the size reached before truncation'
        );
    }

    public function testToolLoopSendsEachMessageOnceAfterInteractionsTruncation(): void {
        $client = $this->createClient(GoogleApiVersion::INTERACTIONS);
        $http = new FakeHttpClient();

        for ($i = 1; $i <= 5; $i++) {
            $http->addResponse($this->interactionsResponse([
                ['type' => 'function_call', 'id' => "call_{$i}", 'name' => 'read_document', 'arguments' => ['n' => $i]],
            ]));
        }
        $http->addResponse($this->interactionsResponse([['type' => 'text', 'text' => 'done']]));

        $this->runLoop($client, $http, $truncations);

        $this->assertNotEmpty($truncations, 'Precondition: the loop must truncate for this test to mean anything');
        $this->assertCount(6, $http->getRequests());

        foreach ($http->getRequests() as $i => $request) {
            $this->assertSame(
                1,
                substr_count($request->getBody(), self::QUESTION),
                "Request #{$i} sends the original question more than once"
            );
        }

        $this->assertStringNotContainsString(
            'document-1-',
            $http->getLastRequest()->getBody(),
            'The oldest tool result was truncated and must not be sent again'
        );
    }

    public function testToolLoopWithoutTruncationKeepsTheCancellationTokenOnEveryRequest(): void {
        foreach ([GoogleApiVersion::GENERATE_CONTENT, GoogleApiVersion::INTERACTIONS] as $version) {
            $client = $this->createClient($version);
            $http = new FakeHttpClient();

            for ($i = 1; $i <= 3; $i++) {
                $http->addResponse($version === GoogleApiVersion::INTERACTIONS
                    ? $this->interactionsResponse([['type' => 'function_call', 'id' => "call_{$i}", 'name' => 'read_document', 'arguments' => ['n' => $i]]])
                    : $this->generateContentResponse(['functionCall' => ['name' => 'read_document', 'args' => ['n' => $i]]]));
            }
            $http->addResponse($version === GoogleApiVersion::INTERACTIONS
                ? $this->interactionsResponse([['type' => 'text', 'text' => 'done']])
                : $this->generateContentResponse(['text' => 'done']));

            $this->runLoop($client, $http, $truncations, false);

            $this->assertSame([], $truncations, 'Precondition: this loop must not truncate');
            $this->assertCount(4, $http->getRequests());

            foreach ($http->getRequests() as $i => $request) {
                $this->assertNotNull(
                    $request->getCancellationToken(),
                    "Request #{$i} lost its cancellation token ({$version->name})"
                );
            }
        }
    }

    /**
     * @param Message[] $messages
     */
    private function build(GoogleClient $client, array $messages): HttpRequest {
        $method = new ReflectionMethod($client, 'buildChatRequest');
        $method->setAccessible(true);

        return $method->invoke($client, $messages, []);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contents(HttpRequest $request): array {
        return json_decode($request->getBody(), true)['contents'];
    }

    private function createClient(GoogleApiVersion $version): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            apiKey: 'test',
            api: GoogleApi::GEMINI,
            apiVersion: $version,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function generateContentResponse(array $part): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [$part]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]));
    }

    /**
     * The tool loop passes the full truncated conversation as $newMessages
     * after resetting its formatted count to 0.
     *
     * @param Message[] $allMessages
     * @param Message[]|null $newMessages Defaults to $allMessages.
     */
    private function incremental(
        GoogleClient $client,
        HttpRequest $previous,
        array $allMessages,
        ?array $newMessages = null
    ): HttpRequest {
        $method = new ReflectionMethod($client, 'buildIncrementalChatRequest');
        $method->setAccessible(true);

        return $method->invoke($client, $previous, $allMessages, $newMessages ?? $allMessages, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function input(HttpRequest $request): array {
        return json_decode($request->getBody(), true)['input'];
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function interactionsResponse(array $steps): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'id' => 'int_'.uniqid(),
            'model' => 'gemini-2.5-flash',
            'steps' => $steps,
            'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 5],
        ]));
    }

    /**
     * Short label of each contents entry ("question", "answer-5", ...).
     *
     * @param array<int, array<string, mixed>> $contents
     *
     * @return string[]
     */
    private function labels(array $contents): array {
        return array_map(fn (array $c) => strtok($c['parts'][0]['text'], ' '), $contents);
    }

    /**
     * Runs an auto-executed tool loop whose tool returns large results, under a
     * small sliding window, with mid-loop truncation enabled.
     *
     * @param array<int, array<string, mixed>>|null $truncations Receives the context of each mid-loop truncation event.
     */
    private function runLoop(GoogleClient $client, FakeHttpClient $http, ?array &$truncations, bool $truncate = true): void {
        $truncations = [];
        $client->setHttpClient($http);
        $client->setContextWindowStrategy(new SlidingWindowStrategy(maxTokens: $truncate ? 2500 : 1000000, reserveForCompletion: 200));
        $client->setStatusEmitter(new CallbackStatusEmitter(function (string $status, array $context) use (&$truncations): void
        {
            if ($status === Status::TRUNCATING_CONTEXT && isset($context['iteration'])) {
                $truncations[] = $context;
            }
        }));

        $tool = new Tool(
            'read_document',
            'Reads a document',
            ['type' => 'object', 'properties' => new stdClass()],
            fn (array $args): string => 'document-'.($args['n'] ?? 0).'-'.str_repeat('y', 6000)
        );

        $token = (new CancellationTokenSource())->getToken();

        $client->chat(
            [new Message(Role::SYSTEM, 'You are an auditor.'), new Message(Role::USER, self::QUESTION)],
            [
                ChatOption::TOOLS => [$tool],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
                ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => $truncate,
                ChatOption::CANCELLATION_TOKEN => $token,
            ]
        );

        // The request sent right after a truncation was built from scratch and
        // must still be cancellable.
        foreach ($truncations as $context) {
            $this->assertSame(
                $token,
                $http->getRequests()[$context['iteration']]->getCancellationToken(),
                "The request after the truncation at iteration {$context['iteration']} lost its cancellation token"
            );
        }
    }
}
