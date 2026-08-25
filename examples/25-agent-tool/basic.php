<?php

declare(strict_types=1);

/**
 * Example 25: AgentTool — Basic Agent Delegation
 *
 * Run: php examples/25-agent-tool/basic.php
 *
 * Demonstrates:
 * 1. Creating an AgentProfile with identity, skills, and instructions
 * 2. Wrapping a provider in an AgentTool
 * 3. Using the agent tool with auto_execute_tools in an orchestrator
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\AgentMessageStrategy;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;

// ─── Setup the orchestrator (primary model) ───────────────────────────────────

$orchestrator = new OpenAIClient(new OpenAIClientConfig(
    apiKey: getenv('OPENAI_API_KEY') ?: 'sk-...',
    model: 'gpt-4o',
));

// ─── Setup the sub-agent provider ────────────────────────────────────────────

$agentProvider = new OpenAIClient(new OpenAIClientConfig(
    apiKey: getenv('OPENAI_API_KEY') ?: 'sk-...',
    model: 'gpt-4o-mini',
));

// ─── Create an AgentProfile ──────────────────────────────────────────────────

$profile = new AgentProfile(
    identity: 'You are a senior PHP developer with 15 years of experience.',
    skills: [
        'PHP 8.x features (enums, fibers, named arguments)',
        'Design patterns (SOLID, DDD, CQRS)',
        'Testing (PHPUnit, Pest)',
        'Performance optimization',
    ],
    instructions: [
        'Always provide working code examples',
        'Use declare(strict_types=1) in all code',
        'Explain trade-offs when multiple approaches exist',
    ],
    constraints: [
        'Only discuss PHP-related topics',
        'Keep responses under 300 words',
    ],
    outputFormat: 'Respond with a brief explanation followed by a code example in a ```php block.',
);

// ─── Create the AgentTool ────────────────────────────────────────────────────

$phpExpert = new AgentTool(
    name: 'php_expert',
    description: 'A specialized PHP developer agent. Delegate PHP questions, '
        .'code reviews, and implementation tasks to this agent.',
    provider: $agentProvider,
    profile: $profile,
    messageStrategy: AgentMessageStrategy::TASK_ONLY,
);

// ─── Use with the orchestrator ────────────────────────────────────────────────

echo "═══ Basic AgentTool Example ═══\n\n";

$messages = [
    new Message('system', 'You are a helpful assistant. Use the php_expert tool '
        .'when the user asks PHP-related questions.'),
    new Message('user', 'How do I implement the Strategy pattern in PHP 8.2?'),
];

$response = $orchestrator->chat($messages, [
    ChatOption::TOOLS => [$phpExpert],
    ChatOption::AUTO_EXECUTE_TOOLS => true,
]);

echo "Question: How do I implement the Strategy pattern in PHP 8.2?\n\n";
echo "Response:\n";
echo $response->getMessage()->getContent()."\n";
