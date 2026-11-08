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
    try {
        $client = $clientFactory();
    } catch (Throwable $e) {
        echo "  \033[33m⚠  SKIP\033[0m  {$label} — ".substr($e->getMessage(), 0, 80)."\n";

        return;
    }

    try {
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
        pass($label);
        echo '    → streamed answer: '.substr(trim($streamed), 0, 80)."\n";
    } catch (AssertionError $e) {
        fail($label, $e->getMessage());
    } catch (Throwable $e) {
        // A model that is not available on this account/endpoint is a skip,
        // not a failure of the streaming tool loop.
        $msg = $e->getMessage();

        if (stripos($msg, 'not found') !== false || stripos($msg, 'not available') !== false
            || stripos($msg, 'does not exist') !== false || stripos($msg, 'unsupported') !== false
            || stripos($msg, '404') !== false) {
            echo "  \033[33m⚠  SKIP\033[0m  {$label} — model unavailable: ".substr($msg, 0, 70)."\n";

            return;
        }

        fail($label, get_class($e).': '.substr($msg, 0, 100));
    }
}

// Anthropic (surfaces streamed tool_use natively).
streamToolCheck('Anthropic — streaming tool loop', fn () => anthropicClient());

// OpenAI — tool_calls deltas parsed in this feature.
streamToolCheck('OpenAI — streaming tool loop', fn () => openaiClient());

// Google generateContent (gemini-2.x) — parser added in this feature.
streamToolCheck('Gemini 2.x — streaming tool loop (generateContent)', fn () => gemini2Client());

// Google Interactions API (gemini-3.x) — function_call steps + arguments_delta.
streamToolCheck('Gemini 3.x — streaming tool loop (Interactions)', fn () => gemini3Client());

// Google Interactions API (gemini-4.x) — only runs when GEMINI_4_MODEL is set
// to a model that exists on the account (no such model is public yet).
if (getenv('GEMINI_4_MODEL')) {
    streamToolCheck('Gemini 4.x — streaming tool loop (Interactions)', fn () => gemini4Client());
} else {
    echo "  \033[33m⚠  SKIP\033[0m  Gemini 4.x — streaming tool loop (set GEMINI_4_MODEL to a released model to enable)\n";
}

// AWS Bedrock Converse — surfaces toolUse blocks natively. Skipped when no
// Bedrock credentials are configured in this environment.
if (getenv('BEDROCK_API_KEY') || (getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY'))) {
    streamToolCheck('Bedrock Converse — streaming tool loop', fn () => bedrockClient());
} else {
    echo "  \033[33m⚠  SKIP\033[0m  Bedrock — streaming tool loop (no Bedrock credentials configured)\n";
}

echo "\n";
