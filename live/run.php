#!/usr/bin/env php
<?php

/**
 * Live Test Runner
 *
 * Runs all live tests against real APIs.
 *
 * Usage:
 *   source keys/env.sh && php live/run.php [suite]
 *
 * Suites:
 *   all       — Run all tests (default)
 *   gemini2   — Gemini 2.x only
 *   gemini3   — Gemini 3.x / Interactions API only
 *   bedrock   — Bedrock only
 *   fallback  — FallbackProvider only
 *
 * Examples:
 *   source keys/env.sh && php live/run.php
 *   source keys/env.sh && php live/run.php bedrock
 */
$suite = $argv[1] ?? 'all';

$suites = [
    'gemini2' => 'live/01-gemini2-chat.php',
    'gemini3' => 'live/02-gemini3-interactions.php',
    'bedrock' => 'live/03-bedrock-chat.php',
    'fallback' => 'live/04-fallback.php',
    'interactions' => 'live/05-interactions-api.php',
    'toolresponse' => 'live/06-tool-response.php',
    'officeimages' => 'live/07-office-images.php',
    'modelgarden' => 'live/08-model-garden.php',
];

$rootDir = __DIR__.'/..';

echo "\n\033[1;37m╔══════════════════════════════════════════╗\033[0m\n";
echo "\033[1;37m║       WebFiori AI — Live Test Suite      ║\033[0m\n";
echo "\033[1;37m╚══════════════════════════════════════════╝\033[0m\n";
echo "\nRunning suite: \033[1;33m{$suite}\033[0m\n";

if ($suite === 'all') {
    foreach ($suites as $name => $file) {
        require "{$rootDir}/{$file}";
    }
} elseif (isset($suites[$suite])) {
    require "{$rootDir}/{$suites[$suite]}";
} else {
    echo "\033[31mUnknown suite: {$suite}\033[0m\n";
    echo "Available: ".implode(', ', array_keys($suites))."\n";
    exit(1);
}

echo "\033[1;37m══ Done ══\033[0m\n\n";
