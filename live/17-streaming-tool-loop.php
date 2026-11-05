<?php

/**
 * Live Test 17: Streaming auto-execute tool loop (ADR-0060)
 *
 * Verifies that streamChat() with tools executes the tool mid-stream and then
 * streams a final text answer — against real providers.
 *
 * Usage:
 *   source keys/env.sh && php live/run.php streamtools
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\Tool;

section('Streaming tool loop (ADR-0060)');

/**
 * A simple weather tool used across providers.
 */
function weatherTool(bool &$ranFlag): Tool {
    return new Tool(
        'get_weather',
        'Returns the current weather for a city.',
        [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
            'required' => ['city'],
        ],
        function (array $args) use (&$ranFlag): string {
            $ranFlag = true;

            return json_encode(['city' => $args['city'] ?? 'unknown', 'temp_c' => 21, 'sky' => 'clear']);
        }
    );
}

/**
 * Runs the streaming-tool-loop check against one provider.
 *
 * @param callable():object $clientFactory Returns a provider client.
 */
function streamToolCheck(string $label, callable $clientFactory): void {
    run($label, function () use ($clientFactory) {
        $client = $clientFactory();
        $toolRan = false;
        $tool = weatherTool($toolRan);

        $streamed = '';
        $completed = null;

        $client->streamChat(
            [new Message('user', 'Use the get_weather tool for Paris, then tell me in one sentence.')],
            function (string $token) use (&$streamed): void {
                $streamed .= $token;
            },
            function ($response) use (&$completed): void {
                $completed = $response;
            },
            null,
            [ChatOption::TOOLS => [$tool], ChatOption::AUTO_EXECUTE_TOOLS => true]
        );

        assert($toolRan, 'the tool should have executed during the stream');
        assert($completed !== null, 'onComplete should fire');
        assert(!$completed->hasToolCalls(), 'final response must be a text answer, not tool calls');
        assert(trim($streamed) !== '', 'the final answer should have streamed tokens');
        echo '    → streamed answer: '.substr(trim($streamed), 0, 80)."\n";
    });
}

// Anthropic (surfaces streamed tool_use natively).
streamToolCheck('Anthropic — streaming tool loop', fn () => anthropicClient());

// Google generateContent (gemini-2.x) — parser added in this feature.
streamToolCheck('Gemini 2.x — streaming tool loop (generateContent)', fn () => gemini2Client());

// NOTE: Gemini 3.x (Interactions API) streaming tool calling is tracked
// separately — its streaming path does not surface function calls live (the
// loop and non-streaming path work). See the follow-up issue.

// AWS Bedrock Converse — surfaces toolUse blocks natively. Skipped when no
// Bedrock credentials are configured in this environment.
if (getenv('BEDROCK_API_KEY') || (getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY'))) {
    streamToolCheck('Bedrock Converse — streaming tool loop', fn () => bedrockClient());
} else {
    echo "  \033[33m⚠  SKIP\033[0m  Bedrock — streaming tool loop (no Bedrock credentials configured)\n";
}

echo "\n";
