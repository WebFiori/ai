<?php

/**
 * Example 17: Structured Output / JSON Mode (CLI)
 *
 * Run: php examples/17-structured-output/json_mode.php
 *
 * Demonstrates:
 * 1. Simple JSON mode (unstructured JSON output)
 * 2. JSON schema (structured output with validation)
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;


$client = new GoogleClient([
    'credentials' => __DIR__.'/../../vertex-ai-key.json',
    'model' => 'gemini-3.5-flash',
]);

// ─── Option A: Simple JSON mode ──────────────────────────────────────────────
echo '═══ JSON Mode ═══'.PHP_EOL.PHP_EOL;

$response = $client->chat(
    [new Message('user', 'List 3 programming languages with their main use case.')],
    ['json_mode' => true]
);

$data = json_decode($response->getMessage()->getContent(), true);
echo 'Raw response:'.PHP_EOL;
echo json_encode($data, JSON_PRETTY_PRINT).PHP_EOL;

// ─── Option B: JSON schema ────────────────────────────────────────────────────
echo PHP_EOL.'═══ JSON Schema ═══'.PHP_EOL.PHP_EOL;

$response = $client->chat(
    [new Message('user', 'Extract the person info: John Doe is 30 years old and works as a software engineer in London.')],
    [
        'json_schema' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Full name of the person',
                ],
                'age' => [
                    'type' => 'integer',
                    'description' => 'Age in years',
                ],
                'job_title' => [
                    'type' => 'string',
                    'description' => 'Job title or occupation',
                ],
                'city' => [
                    'type' => 'string',
                    'description' => 'City of residence',
                ],
            ],
            'required' => ['name', 'age', 'job_title', 'city'],
        ],
    ]
);

$person = json_decode($response->getMessage()->getContent(), true);
echo 'Extracted person:'.PHP_EOL;
echo json_encode($person, JSON_PRETTY_PRINT).PHP_EOL;

// ─── Option C: Product catalog extraction ────────────────────────────────────
echo PHP_EOL.'═══ Product Extraction ═══'.PHP_EOL.PHP_EOL;

$response = $client->chat(
    [
        new Message('user',
            'Extract products from: "We sell the Pro Laptop for $1299, '.
            'the Basic Tablet at $399 (out of stock), and Wireless Headphones for $79."'
        ),
    ],
    [
        'json_schema' => [
            'type' => 'object',
            'properties' => [
                'products' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'price_usd' => ['type' => 'number'],
                            'in_stock' => ['type' => 'boolean'],
                        ],
                        'required' => ['name', 'price_usd', 'in_stock'],
                    ],
                ],
            ],
            'required' => ['products'],
        ],
    ]
);

$catalog = json_decode($response->getMessage()->getContent(), true);
echo 'Products:'.PHP_EOL;

foreach ($catalog['products'] as $product) {
    $stock = $product['in_stock'] ? '✅ In stock' : '❌ Out of stock';
    printf("  - %-22s $%.2f  %s\n", $product['name'], $product['price_usd'], $stock);
}
