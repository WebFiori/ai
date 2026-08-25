<?php

/**
 * Live Test 12: AgentTool — AI agent delegation
 *
 * Usage:
 *   source keys/env.sh && php live/12-agent-tool.php
 */

require_once __DIR__.'/helpers.php';

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\AgentMessageStrategy;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\Tool;

section('AgentTool — AI Agent Delegation');

// ─── 1. Basic delegation (TASK_ONLY) ─────────────────────────────────────────
run('AgentTool — basic delegation (TASK_ONLY)', function ()
{
    $profile = new AgentProfile(
        identity: 'You are a concise geography expert.',
        skills: ['world capitals', 'country facts'],
    );

    $agent = new AgentTool(
        name: 'geography_expert',
        description: 'A geography expert that can answer questions about world capitals and country facts.',
        provider: gemini2Client(),
        profile: $profile,
        messageStrategy: AgentMessageStrategy::TASK_ONLY,
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the geography expert: what is the capital of Jordan?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert(stripos($content, 'Amman') !== false, 'Response should mention Amman, got: '.substr($content, 0, 200));
    echo "    → ".substr($content, 0, 100)."\n";
});

// ─── 2. Profile with instructions ────────────────────────────────────────────
run('AgentTool — profile with instructions', function ()
{
    $profile = new AgentProfile(
        identity: 'You are a helpful assistant.',
        instructions: ['Always respond in exactly one sentence.'],
    );

    $agent = new AgentTool(
        name: 'concise_assistant',
        description: 'An assistant that responds in exactly one sentence.',
        provider: gemini2Client(),
        profile: $profile,
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the concise assistant: What is the purpose of unit testing?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert($content !== '', 'Response should not be empty');
    echo "    → ".substr($content, 0, 100)."\n";
});

// ─── 3. Profile with constraints ─────────────────────────────────────────────
run('AgentTool — profile with constraints', function ()
{
    $profile = new AgentProfile(
        identity: 'You are a programming language expert.',
        constraints: ['Only answer in French.'],
    );

    $agent = new AgentTool(
        name: 'french_expert',
        description: 'A programming expert that always answers in French.',
        provider: gemini2Client(),
        profile: $profile,
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the french expert: What is PHP?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert($content !== '', 'Response should not be empty');
    echo "    → ".substr($content, 0, 100)."\n";
});

// ─── 4. Custom options (low temperature) ─────────────────────────────────────
run('AgentTool — custom options (low temperature)', function ()
{
    $profile = new AgentProfile(
        identity: 'You are a factual assistant that gives precise answers.',
    );

    $agent = new AgentTool(
        name: 'precise_assistant',
        description: 'A factual assistant that gives precise, deterministic answers.',
        provider: gemini2Client(),
        profile: $profile,
        options: [ChatOption::TEMPERATURE => 0.1],
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the precise assistant: What year was PHP first released?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert($content !== '', 'Response should not be empty');
    echo "    → ".substr($content, 0, 100)."\n";
});

// ─── 5. Agent with sub-tools ─────────────────────────────────────────────────
run('AgentTool — agent with sub-tools', function ()
{
    $calculator = new Tool(
        'calculate',
        'Performs arithmetic calculations. Supports add, subtract, multiply, divide.',
        [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['add', 'subtract', 'multiply', 'divide'],
                    'description' => 'The arithmetic operation to perform.',
                ],
                'a' => ['type' => 'number', 'description' => 'First operand.'],
                'b' => ['type' => 'number', 'description' => 'Second operand.'],
            ],
            'required' => ['operation', 'a', 'b'],
        ],
        function (array $args): string
        {
            $a = $args['a'];
            $b = $args['b'];

            return match ($args['operation']) {
                'add' => json_encode(['result' => $a + $b]),
                'subtract' => json_encode(['result' => $a - $b]),
                'multiply' => json_encode(['result' => $a * $b]),
                'divide' => $b != 0 ? json_encode(['result' => $a / $b]) : json_encode(['error' => 'Division by zero']),
                default => json_encode(['error' => 'Unknown operation']),
            };
        }
    );

    $profile = new AgentProfile(
        identity: 'You are a math assistant. Use the calculate tool for arithmetic.',
        skills: ['arithmetic calculations'],
        tools: [$calculator],
    );

    $agent = new AgentTool(
        name: 'math_agent',
        description: 'A math assistant that can perform arithmetic calculations using a calculator tool.',
        provider: gemini2Client(),
        profile: $profile,
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'Ask the math agent: What is 47 multiplied by 23?')],
        [
            ChatOption::TOOLS => [$agent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert(strpos($content, '1081') !== false, 'Response should contain 1081, got: '.substr($content, 0, 200));
    echo "    → ".substr($content, 0, 100)."\n";
});

// ─── 6. AgentProfile — fromFile loading ──────────────────────────────────────
run('AgentProfile — fromFile loading', function ()
{
    $profileData = [
        'identity' => 'You are a code review assistant.',
        'skills' => ['code quality analysis', 'best practices'],
        'instructions' => ['Be constructive and specific.', 'Suggest improvements.'],
        'constraints' => ['Do not rewrite entire files.'],
        'output_format' => 'Markdown with bullet points.',
        'context' => 'You review PHP code for the WebFiori framework.',
    ];

    $tempFile = tempnam(sys_get_temp_dir(), 'agent_profile_').'.json';
    file_put_contents($tempFile, json_encode($profileData, JSON_PRETTY_PRINT));

    try {
        $profile = AgentProfile::fromFile($tempFile);

        // Verify profile loaded correctly
        assert($profile->getIdentity() === 'You are a code review assistant.', 'Identity mismatch');
        assert($profile->getSkills() === ['code quality analysis', 'best practices'], 'Skills mismatch');
        assert($profile->getInstructions() === ['Be constructive and specific.', 'Suggest improvements.'], 'Instructions mismatch');
        assert($profile->getConstraints() === ['Do not rewrite entire files.'], 'Constraints mismatch');
        assert($profile->getOutputFormat() === 'Markdown with bullet points.', 'Output format mismatch');
        assert($profile->getContext() === 'You review PHP code for the WebFiori framework.', 'Context mismatch');

        // Verify render output
        $rendered = $profile->render();
        assert(strpos($rendered, 'You are a code review assistant.') !== false, 'Rendered missing identity');
        assert(strpos($rendered, '## Skills') !== false, 'Rendered missing skills section');
        assert(strpos($rendered, '## Constraints') !== false, 'Rendered missing constraints section');

        // Use it in an agent
        $agent = new AgentTool(
            name: 'code_reviewer',
            description: 'A code review assistant for PHP.',
            provider: gemini2Client(),
            profile: $profile,
        );

        $orchestrator = gemini2Client();
        $response = $orchestrator->chat(
            [new Message('user', 'Ask the code reviewer: What are 2 best practices for PHP error handling?')],
            [
                ChatOption::TOOLS => [$agent],
                ChatOption::AUTO_EXECUTE_TOOLS => true,
            ]
        );

        $content = $response->getMessage()->getContent();
        assert($content !== '', 'Response should not be empty');
        echo "    → Profile loaded and rendered correctly\n";
        echo "    → ".substr($content, 0, 100)."\n";
    } finally {
        @unlink($tempFile);
    }
});

// ─── 7. Multiple agents — orchestrator delegates to specialists ──────────────
run('Multiple agents — orchestrator delegates to specialists', function ()
{
    $mathProfile = new AgentProfile(
        identity: 'You are a math expert. Answer math questions precisely.',
        skills: ['arithmetic', 'algebra', 'calculus'],
    );

    $mathAgent = new AgentTool(
        name: 'math_expert',
        description: 'A math expert that can answer arithmetic, algebra, and calculus questions.',
        provider: gemini2Client(),
        profile: $mathProfile,
    );

    $languageProfile = new AgentProfile(
        identity: 'You are a language expert specializing in etymology and linguistics.',
        skills: ['etymology', 'linguistics', 'grammar', 'translations'],
    );

    $languageAgent = new AgentTool(
        name: 'language_expert',
        description: 'A language expert that can answer questions about etymology, linguistics, grammar, and translations.',
        provider: gemini2Client(),
        profile: $languageProfile,
    );

    $orchestrator = gemini2Client();
    $response = $orchestrator->chat(
        [new Message('user', 'What is the square root of 144?')],
        [
            ChatOption::TOOLS => [$mathAgent, $languageAgent],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
        ]
    );

    $content = $response->getMessage()->getContent();
    assert(strpos($content, '12') !== false, 'Response should contain 12, got: '.substr($content, 0, 200));
    echo "    → ".substr($content, 0, 100)."\n";
});

echo "\n";
