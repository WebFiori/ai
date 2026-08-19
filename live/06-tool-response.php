<?php

/**
 * Live Test 06: ToolResponse — multimodal tool outputs
 *
 * Usage:
 *   source keys/env.sh && php live/06-tool-response.php
 *
 * Tests ToolResponse across Gemini 2.x (generateContent) and Bedrock.
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolResponse;
use WebFiori\Ai\Tool\ToolResult;

section('ToolResponse — Multimodal Tool Outputs');

// Shared: a tiny valid 1×1 white PNG (35 bytes) for testing
$TINY_PNG = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
);

// ─── 1. ToolResponse::text() works like a plain string ────────────────────────
run('ToolResponse::text() — backward compatible', function ()
{
    $tool = new Tool(
        'get_info',
        'Returns a fixed PHP description.',
        [
            'type' => 'object',
            'properties' => [
                'topic' => ['type' => 'string', 'description' => 'The topic to get info about'],
            ],
            'required' => ['topic'],
        ],
        fn(array $args) => ToolResponse::text('PHP is a server-side scripting language.')
    );

    $response = gemini2Client()->chat(
        [new Message('user', 'Get info about PHP.')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 2. ToolResponse with image — Gemini 2.x ─────────────────────────────────
run('ToolResponse with image — Gemini 2.x (generateContent)', function () use ($TINY_PNG)
{
    $tool = new Tool(
        'get_chart',
        'Returns a chart as an image.',
        ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
        fn(array $args) => ToolResponse::withImages(
            json_encode(['title' => $args['title'], 'status' => 'Chart ready']),
            [ContentPart::imageBase64(base64_encode($TINY_PNG), 'image/png')]
        )
    );

    $response = gemini2Client()->chat(
        [new Message('user', 'Generate a chart titled "Revenue" and describe what you see.')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 3. ToolResponse with image — Gemini 3.x (Interactions API) ───────────────
// NOTE: The Interactions API does not yet support images in function_result.
// Images are silently dropped (text-only fallback). This test verifies
// the tool still executes and the final response is non-empty.
run('ToolResponse with image — Gemini 3.x (text fallback — images not yet supported in API)', function () use ($TINY_PNG)
{
    $tool = new Tool(
        'get_chart',
        'Returns a chart description.',
        ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => 'Chart title']], 'required' => ['title']],
        fn(array $args) => ToolResponse::withImages(
            json_encode(['title' => $args['title'], 'status' => 'Chart ready']),
            [ContentPart::imageBase64(base64_encode($TINY_PNG), 'image/png')]
        )
    );

    $response = gemini3Client()->chat(
        [new Message('user', 'Generate a chart titled "Q3 Sales" and describe it.')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    // The tool executes, images are dropped (API limitation), text goes through
    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";
    echo "    → (Images in function_result not yet supported by Interactions API)\n";
});

// ─── 4. ToolResponse with image — Bedrock ─────────────────────────────────────
run('ToolResponse with image — Bedrock (Nova Lite)', function () use ($TINY_PNG)
{
    $tool = new Tool(
        'get_diagram',
        'Returns a system diagram as an image.',
        ['type' => 'object', 'properties' => ['system' => ['type' => 'string']]],
        fn(array $args) => ToolResponse::withImages(
            json_encode(['system' => $args['system'], 'status' => 'Diagram ready']),
            [ContentPart::imageBase64(base64_encode($TINY_PNG), 'image/png')]
        )
    );

    $response = bedrockClient()->chat(
        [new Message('user', 'Generate a diagram for system "auth" and describe it.')],
        ['tools' => [$tool], 'auto_execute_tools' => true]
    );

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 100)."\n";
});

// ─── 5. ToolResult::isMultimodal() ────────────────────────────────────────────
run('ToolResult carries ContentPart[] and isMultimodal()', function () use ($TINY_PNG)
{
    $part = ContentPart::imageBase64(base64_encode($TINY_PNG), 'image/png');
    $result = new ToolResult('call_1', 'text output', 'my_tool', [$part]);

    assert($result->isMultimodal() === true, 'Should be multimodal');
    assert(count($result->getParts()) === 1, 'Should have 1 part');
    assert($result->getName() === 'my_tool', 'Wrong name');
    echo "    → isMultimodal: true, parts: 1, name: my_tool\n";
});

// ─── 6. ToolResponse::withParts() ─────────────────────────────────────────────
run('ToolResponse::withParts() factory', function () use ($TINY_PNG)
{
    $parts = [
        ContentPart::imageBase64(base64_encode($TINY_PNG), 'image/png'),
        ContentPart::imageBase64(base64_encode($TINY_PNG), 'image/png'),
    ];
    $response = ToolResponse::withParts('Two images included.', $parts);

    assert($response->isMultimodal() === true, 'Should be multimodal');
    assert(count($response->getParts()) === 2, 'Should have 2 parts');
    assert((string) $response === 'Two images included.', '__toString mismatch');
    echo "    → parts: 2, text: '".$response->getText()."'\n";
});

echo "\n";
