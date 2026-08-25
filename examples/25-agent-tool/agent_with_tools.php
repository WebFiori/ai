<?php

declare(strict_types=1);

/**
 * Example 25: AgentTool — Agent with Sub-tools (Nested Tool Execution)
 *
 * Run: php examples/25-agent-tool/agent_with_tools.php
 *
 * Demonstrates:
 * 1. An agent that has its own tools (sub-tools)
 * 2. Nested tool execution: orchestrator → agent → agent's tools
 * 3. Profile with tools set programmatically
 */
require_once __DIR__.'/../../vendor/autoload.php';

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
    model: 'gemini-2.5-pro',
));

$agentProvider = new GoogleClient(new GoogleClientConfig(
    apiKey: 'your-api-key',
    model: 'gemini-2.5-flash',
));

// ─── Define sub-tools for the research agent ──────────────────────────────────

$searchTool = new Tool(
    name: 'web_search',
    description: 'Search the web for current information on a topic.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'The search query.',
            ],
            'max_results' => [
                'type' => 'integer',
                'description' => 'Maximum number of results to return (1-10).',
            ],
        ],
        'required' => ['query'],
    ],
    handler: function (array $args): string
    {
        // Simulated web search results
        $query = $args['query'];
        $maxResults = $args['max_results'] ?? 3;

        $results = [
            [
                'title' => "Understanding {$query} - Wikipedia",
                'url' => "https://en.wikipedia.org/wiki/".urlencode($query),
                'snippet' => "A comprehensive overview of {$query} covering history, concepts, and applications.",
            ],
            [
                'title' => "{$query} Best Practices - Dev.to",
                'url' => "https://dev.to/article/".urlencode($query),
                'snippet' => "Modern best practices and patterns for working with {$query} in production.",
            ],
            [
                'title' => "{$query} Documentation",
                'url' => "https://docs.example.com/".urlencode($query),
                'snippet' => "Official documentation and API reference for {$query}.",
            ],
        ];

        return json_encode(array_slice($results, 0, $maxResults));
    },
);

$summarizeTool = new Tool(
    name: 'summarize_url',
    description: 'Fetch and summarize the content of a URL.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'The URL to fetch and summarize.',
            ],
        ],
        'required' => ['url'],
    ],
    handler: function (array $args): string
    {
        // Simulated URL summarization
        $url = $args['url'];

        return json_encode([
            'url' => $url,
            'summary' => "This page covers key concepts, implementation details, "
                ."and practical examples. Main topics include architecture decisions, "
                ."performance considerations, and integration patterns.",
            'word_count' => 2500,
        ]);
    },
);

// ─── Create a research agent with sub-tools ───────────────────────────────────

$researchProfile = new AgentProfile(
    identity: 'You are a research assistant specialized in gathering and synthesizing '
        .'information from multiple sources.',
    skills: [
        'Web research and fact-finding',
        'Summarizing technical content',
        'Cross-referencing multiple sources',
        'Identifying key insights and trends',
    ],
    instructions: [
        'Search for information using the web_search tool',
        'Summarize relevant URLs with summarize_url',
        'Synthesize findings into a clear, structured response',
        'Cite sources with URLs',
    ],
    constraints: [
        'Only report verified information from search results',
        'Clearly distinguish facts from analysis',
    ],
    outputFormat: "Structure response as:\n"
        ."## Summary\nBrief overview\n\n"
        ."## Key Findings\nBulleted list\n\n"
        ."## Sources\nList of URLs used",
);

// Set tools directly on the profile
$researchProfile->setTools([$searchTool, $summarizeTool]);

$researchAgent = new AgentTool(
    name: 'research_agent',
    description: 'A research agent that can search the web and summarize content. '
        .'Use when the user needs information gathered from external sources.',
    provider: $agentProvider,
    profile: $researchProfile,
);

// ─── Use with orchestrator ────────────────────────────────────────────────────

echo "═══ Agent with Sub-tools (Nested Execution) ═══\n\n";
echo "The orchestrator delegates to the research agent, which uses its own\n";
echo "web_search and summarize_url tools internally.\n\n";

$messages = [
    new Message('system', 'You are a helpful assistant. Use the research_agent tool '
        .'when users ask questions that require looking up current information.'),
    new Message('user', 'What are the latest best practices for PHP dependency injection?'),
];

$response = $orchestrator->chat($messages, [
    ChatOption::TOOLS => [$researchAgent],
    ChatOption::AUTO_EXECUTE_TOOLS => true,
]);

echo "Question: What are the latest best practices for PHP dependency injection?\n\n";
echo "Response (from research agent with sub-tool execution):\n";
echo $response->getMessage()->getContent()."\n";
