<?php

declare(strict_types=1);

/**
 * Example 25: AgentTool — Loading Profile from JSON File
 *
 * Run: php examples/25-agent-tool/profile_from_file.php
 *
 * Demonstrates:
 * 1. Loading an AgentProfile from a JSON file
 * 2. Resolving tool references declared in the profile
 * 3. Using the profile-based agent with an orchestrator
 */
require_once __DIR__ . '/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\Tool;

// ─── Setup providers ──────────────────────────────────────────────────────────

$orchestrator = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-api-key',
    model: 'gemini-2.5-flash',
));

$agentProvider = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-api-key',
    model: 'gemini-2.5-flash',
));

// ─── Load profile from JSON file ─────────────────────────────────────────────

$profile = AgentProfile::fromFile(__DIR__ . '/agent-profile.json');

echo "═══ Profile from File Example ═══\n\n";
echo "Loaded profile identity: " . $profile->getIdentity() . "\n";
echo "Skills: " . implode(', ', $profile->getSkills()) . "\n";
echo "Unresolved tool refs: " . implode(', ', $profile->getUnresolvedToolRefs()) . "\n\n";

// ─── Resolve tool references ──────────────────────────────────────────────────

// The JSON profile references "search_docs" — we must resolve it to a real tool
$searchDocsTool = new Tool(
    name: 'search_docs',
    description: 'Search WebFiori framework documentation for relevant information.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'The search query for documentation lookup.',
            ],
        ],
        'required' => ['query'],
    ],
    handler: function (array $args): string {
        // Simulated documentation search
        $query = $args['query'];

        return json_encode([
            'results' => [
                ['title' => 'Router::addRoute()', 'snippet' => 'Adds a new route to the application...'],
                ['title' => 'Route Parameters', 'snippet' => 'Use {param} syntax for dynamic segments...'],
            ],
            'query' => $query,
        ]);
    },
);

// Resolve references from the profile JSON
$profile->resolveTools([
    'search_docs' => $searchDocsTool,
]);

echo "After resolution — tools available: " . count($profile->getTools()) . "\n";
echo "Unresolved refs remaining: " . count($profile->getUnresolvedToolRefs()) . "\n\n";

// ─── Create AgentTool with loaded profile ─────────────────────────────────────

$webfioriAgent = new AgentTool(
    name: 'webfiori_expert',
    description: 'A WebFiori framework expert. Delegates questions about WebFiori '
        . 'routing, ORM, CLI, and templating to this specialized agent.',
    provider: $agentProvider,
    profile: $profile,
);

// ─── Use with orchestrator ────────────────────────────────────────────────────

$messages = [
    new Message('system', 'You are a helpful assistant. Use the webfiori_expert '
        . 'tool for WebFiori framework questions.'),
    new Message('user', 'How do I create a REST API route in WebFiori?'),
];

$response = $orchestrator->chat($messages, [
    ChatOption::TOOLS => [$webfioriAgent],
    ChatOption::AUTO_EXECUTE_TOOLS => true,
]);

echo "Question: How do I create a REST API route in WebFiori?\n\n";
echo "Response:\n";
echo $response->getMessage()->getContent() . "\n";
