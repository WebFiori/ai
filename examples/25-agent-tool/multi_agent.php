<?php

declare(strict_types=1);

/**
 * Example 25: AgentTool — Multi-Agent Orchestration
 *
 * Run: php examples/25-agent-tool/multi_agent.php
 *
 * Demonstrates:
 * 1. Multiple specialized agents registered as tools
 * 2. Orchestrator delegates to the right agent based on user intent
 * 3. Different profiles for different expertise areas
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;

// ─── Setup providers ──────────────────────────────────────────────────────────

$orchestrator = new OpenAIClient(new OpenAIClientConfig(
    apiKey: 'sk-...',
    model: 'gpt-4o',
));

$agentProvider = new OpenAIClient(new OpenAIClientConfig(
    apiKey: 'sk-...',
    model: 'gpt-4o-mini',
));

// ─── Agent 1: WebFiori Expert ─────────────────────────────────────────────────

$webfioriExpert = new AgentTool(
    name: 'webfiori_expert',
    description: 'A WebFiori framework expert. Use for questions about routing, '
        .'middleware, database layer, CLI commands, and application structure.',
    provider: $agentProvider,
    profile: new AgentProfile(
        identity: 'You are a senior developer specializing in the WebFiori PHP framework.',
        skills: [
            'HTTP routing and middleware',
            'Database ORM and query builder',
            'CLI command creation',
            'Template engine and views',
            'Session and authentication',
        ],
        instructions: [
            'Always show the full namespace in use statements',
            'Provide complete, runnable code snippets',
            'Reference official WebFiori documentation when relevant',
        ],
        constraints: [
            'Only discuss WebFiori framework topics',
            'Do not recommend other frameworks',
        ],
    ),
);

// ─── Agent 2: Code Reviewer ──────────────────────────────────────────────────

$codeReviewer = new AgentTool(
    name: 'code_reviewer',
    description: 'A code review specialist. Use when the user wants code reviewed '
        .'for quality, bugs, security issues, or performance problems.',
    provider: $agentProvider,
    profile: new AgentProfile(
        identity: 'You are an expert code reviewer with deep knowledge of PHP best practices.',
        skills: [
            'Identifying bugs and logic errors',
            'Security vulnerability detection (SQL injection, XSS, CSRF)',
            'Performance bottleneck identification',
            'SOLID principles and clean code',
            'PSR compliance checking',
        ],
        instructions: [
            'Categorize findings as: Bug, Security, Performance, Style',
            'Rate severity: Critical, Major, Minor, Suggestion',
            'Provide a corrected code snippet for each issue found',
        ],
        constraints: [
            'Focus on actionable feedback only',
            'Do not rewrite entire files — point to specific lines/sections',
        ],
        outputFormat: "Use this format:\n"
            ."## [Severity] Category: Brief Title\n"
            ."**Issue:** description\n"
            ."**Fix:** corrected code",
    ),
);

// ─── Orchestrator System Prompt ───────────────────────────────────────────────

$systemPrompt = <<<'PROMPT'
You are a development assistant. You have access to two specialized agents:

1. webfiori_expert — for WebFiori framework questions and implementation guidance
2. code_reviewer — for reviewing code quality, bugs, and security

Analyze the user's request and delegate to the appropriate agent. If the request
doesn't match either specialty, answer it yourself.
PROMPT;

// ─── Example 1: WebFiori question → webfiori_expert ───────────────────────────

echo "═══ Multi-Agent Orchestration ═══\n\n";
echo "--- Question 1: WebFiori routing ---\n\n";

$response = $orchestrator->chat(
    [
        new Message('system', $systemPrompt),
        new Message('user', 'How do I set up middleware for authentication in WebFiori?'),
    ],
    [
        ChatOption::TOOLS => [$webfioriExpert, $codeReviewer],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
    ],
);

echo $response->getMessage()->getContent()."\n\n";

// ─── Example 2: Code review → code_reviewer ──────────────────────────────────

echo "--- Question 2: Code review ---\n\n";

$codeToReview = <<<'PHP'
function getUser($id) {
    $db = new PDO("mysql:host=localhost;dbname=app", "root", "");
    $result = $db->query("SELECT * FROM users WHERE id = " . $id);
    return $result->fetch();
}
PHP;

$response = $orchestrator->chat(
    [
        new Message('system', $systemPrompt),
        new Message('user', "Please review this code:\n```php\n{$codeToReview}\n```"),
    ],
    [
        ChatOption::TOOLS => [$webfioriExpert, $codeReviewer],
        ChatOption::AUTO_EXECUTE_TOOLS => true,
    ],
);

echo $response->getMessage()->getContent()."\n";
