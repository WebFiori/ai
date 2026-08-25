<?php

/**
 * Live Test 11: Dynamic Temperature Strategy
 *
 * Usage:
 *   source keys/env.sh && php live/11-dynamic-temperature.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Temperature\FixedTemperatureStrategy;
use WebFiori\Ai\Temperature\TaskBasedTemperatureStrategy;

section('Dynamic Temperature Strategy');

// ─── 1. FixedTemperatureStrategy — always same temp ───────────────────────────
run('FixedTemperatureStrategy — always same temp', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new FixedTemperatureStrategy(0.3));

    $response = $client->chat([
        new Message('user', 'Write a creative metaphor about clouds.'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 2. TaskBasedTemperatureStrategy — factual query ──────────────────────────
run('TaskBasedTemperatureStrategy — factual query', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new TaskBasedTemperatureStrategy());

    $response = $client->chat([
        new Message('user', 'What is the capital of France?'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 3. TaskBasedTemperatureStrategy — creative task ──────────────────────────
run('TaskBasedTemperatureStrategy — creative task', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new TaskBasedTemperatureStrategy());

    $response = $client->chat([
        new Message('user', 'Write a short haiku about PHP programming'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 4. TaskBasedTemperatureStrategy — code task ──────────────────────────────
run('TaskBasedTemperatureStrategy — code task', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new TaskBasedTemperatureStrategy());

    $response = $client->chat([
        new Message('user', 'Implement a PHP function that reverses a string'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 5. TaskBasedTemperatureStrategy — analytical ─────────────────────────────
run('TaskBasedTemperatureStrategy — analytical', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new TaskBasedTemperatureStrategy());

    $response = $client->chat([
        new Message('user', 'Compare PHP and Python for web development in 2 sentences'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 6. Explicit temperature overrides strategy ───────────────────────────────
run('Explicit temperature overrides strategy', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new FixedTemperatureStrategy(0.1));

    $response = $client->chat(
        [new Message('user', 'Tell me a fun fact about elephants.')],
        ['temperature' => 0.9]
    );

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

// ─── 7. Custom buckets ────────────────────────────────────────────────────────
run('Custom buckets', function ()
{
    $client = gemini2Client();
    $client->setTemperatureStrategy(new TaskBasedTemperatureStrategy(
        buckets: [
            ['temperature' => 0.1, 'keywords' => ['diagnose', 'error', 'exception', 'stacktrace']],
            ['temperature' => 0.4, 'keywords' => ['optimize', 'performance', 'benchmark']],
            ['temperature' => 1.0, 'keywords' => ['brainstorm', 'ideate', 'explore']],
        ],
        default: 0.5
    ));

    $response = $client->chat([
        new Message('user', 'Brainstorm 3 creative names for a PHP framework.'),
    ]);

    assert($response->getMessage()->getContent() !== '', 'Empty response');
    echo "    → ".substr($response->getMessage()->getContent(), 0, 80)."\n";
});

echo "\n";
