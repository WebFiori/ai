<?php

declare(strict_types=1);

/**
 * Example 30: Domain Boundaries — Guardrails for Off-Topic Questions
 *
 * Run: php examples/30-domain-boundaries/basic.php
 *
 * Demonstrates:
 * 1. Attaching DomainBoundaries to an AgentProfile (the profile IS the domain)
 * 2. Off-topic questions are redirected WITHOUT any provider/API call
 * 3. In-scope and adjacent questions pass through to the model
 * 4. The optional ChatOption::DOMAIN_BOUNDARIES entry point for raw chat()
 * 5. Tracing decisions via evaluate() (reason, matched pattern, strategy)
 */
require_once __DIR__.'/../../vendor/autoload.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\DomainBoundaries;

// ─── Define the domain boundary ───────────────────────────────────────────────

$boundaries = new DomainBoundaries(
    inScope: ['Sales data', 'Stock levels', 'Forecasts'],
    adjacentAllowed: ['market factors', 'geopolitical'],
    blockPatterns: [
        'weather|forecast|temperature',
        'lyrics|song|movie|entertainment',
        'relationship|dating|personal advice',
    ],
    redirectTemplate: "That's outside my focus as Business AI! I specialize in "
        ."sales, stock, and forecast data. Want me to check inventory levels or "
        ."analyze revenue trends instead?",
);

// ─── Attach it to an agent profile (the profile owns its domain) ──────────────

$profile = new AgentProfile(
    identity: 'You are Business AI, an analyst for sales, stock, and forecasts.',
    skills: ['Sales analysis', 'Inventory tracking', 'Revenue forecasting'],
    domainBoundaries: $boundaries,
);

$provider = new OpenAIClient(new OpenAIClientConfig(
    apiKey: getenv('OPENAI_API_KEY') ?: 'sk-...',
    model: 'gpt-4o-mini',
));

$agent = new AgentTool(
    name: 'business_ai',
    description: 'Answers questions about sales, stock, and forecasts.',
    provider: $provider,
    profile: $profile,
);

// ─── 1. Off-topic question — redirected with NO API call ──────────────────────

echo "═══ Domain Boundaries Example ═══\n\n";

echo "Q: What's the weather tomorrow?\n";
echo 'A: '.$agent->execute(['task' => "What's the weather tomorrow?"])."\n\n";

// ─── 2. Inspect WHY a decision was made (tracing) ─────────────────────────────

$decision = $boundaries->evaluate("What's the weather tomorrow?");
echo "Trace for the blocked question:\n";
echo '  blocked         : '.($decision->isBlocked() ? 'yes' : 'no')."\n";
echo '  reason          : '.$decision->getReason()."\n";
echo '  strategy        : '.$decision->getStrategy()."\n";
echo '  matched_pattern : '.($decision->getMatchedPattern() ?? 'null')."\n\n";

// ─── 3. Adjacent question — allowed through despite touching "forecast" ───────

$adjacent = $boundaries->evaluate('How do market factors affect our stock forecast?');
echo "Q: How do market factors affect our stock forecast?\n";
echo '  reason          : '.$adjacent->getReason()." (adjacency wins)\n";
echo '  would call model: '.($adjacent->isBlocked() ? 'no' : 'yes')."\n\n";

// ─── 4. Optional chat() entry point for raw, profile-less chat ────────────────

echo "Raw chat() with ChatOption::DOMAIN_BOUNDARIES:\n";
$response = $provider->chat(
    [new Message('user', 'Sing me a song about the ocean')],
    [ChatOption::DOMAIN_BOUNDARIES => $boundaries]
);
echo '  finish_reason   : '.$response->getFinishReason()."\n";
echo '  tokens_used     : '.$response->getUsage()->getTotalTokens()." (short-circuited)\n";
echo '  message         : '.$response->getMessage()->getContent()."\n\n";

echo "Note: off-topic questions never reached the API — zero tokens spent.\n";
