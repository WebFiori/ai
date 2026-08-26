<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Tool;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for AgentProfile.
 */
class AgentProfileTest extends TestCase {
    // =========================================================================
    // Construction
    // =========================================================================

    public function testConstructionWithAllFields(): void {
        $tool = new Tool('test_tool', 'A test tool', ['type' => 'object'], fn () => 'ok');

        $profile = new AgentProfile(
            identity: 'You are a coding assistant.',
            skills: ['PHP', 'JavaScript'],
            instructions: ['Be concise', 'Use examples'],
            constraints: ['No opinions', 'Stay on topic'],
            outputFormat: 'JSON',
            context: 'The user is a senior developer.',
            examples: [
                ['input' => 'Hello', 'output' => 'Hi there!'],
            ],
            metadata: ['version' => '1.0', 'author' => 'test'],
            tools: [$tool],
        );

        $this->assertSame('You are a coding assistant.', $profile->getIdentity());
        $this->assertSame(['PHP', 'JavaScript'], $profile->getSkills());
        $this->assertSame(['Be concise', 'Use examples'], $profile->getInstructions());
        $this->assertSame(['No opinions', 'Stay on topic'], $profile->getConstraints());
        $this->assertSame('JSON', $profile->getOutputFormat());
        $this->assertSame('The user is a senior developer.', $profile->getContext());
        $this->assertCount(1, $profile->getExamples());
        $this->assertSame(['version' => '1.0', 'author' => 'test'], $profile->getMetadata());
        $this->assertCount(1, $profile->getTools());
        $this->assertSame($tool, $profile->getTools()[0]);
    }

    public function testConstructionWithMinimalFields(): void {
        $profile = new AgentProfile(identity: 'A simple assistant.');

        $this->assertSame('A simple assistant.', $profile->getIdentity());
        $this->assertSame([], $profile->getSkills());
        $this->assertSame([], $profile->getInstructions());
        $this->assertSame([], $profile->getConstraints());
        $this->assertNull($profile->getOutputFormat());
        $this->assertNull($profile->getContext());
        $this->assertSame([], $profile->getExamples());
        $this->assertSame([], $profile->getMetadata());
        $this->assertSame([], $profile->getTools());
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function testGetters(): void {
        $profile = new AgentProfile(
            identity: 'Identity text',
            skills: ['skill1'],
            instructions: ['instruction1'],
            constraints: ['constraint1'],
            outputFormat: 'Markdown',
            context: 'Some context',
            examples: [['input' => 'Q', 'output' => 'A']],
            metadata: ['key' => 'value'],
        );

        $this->assertSame('Identity text', $profile->getIdentity());
        $this->assertSame(['skill1'], $profile->getSkills());
        $this->assertSame(['instruction1'], $profile->getInstructions());
        $this->assertSame(['constraint1'], $profile->getConstraints());
        $this->assertSame('Markdown', $profile->getOutputFormat());
        $this->assertSame('Some context', $profile->getContext());
        $this->assertEquals([['input' => 'Q', 'output' => 'A']], $profile->getExamples());
        $this->assertSame(['key' => 'value'], $profile->getMetadata());
    }

    // =========================================================================
    // render()
    // =========================================================================

    public function testRender_FullProfile(): void {
        $profile = new AgentProfile(
            identity: 'You are a helpful assistant.',
            skills: ['PHP', 'Python'],
            instructions: ['Be clear', 'Be brief'],
            constraints: ['No profanity'],
            outputFormat: 'Plain text',
            context: 'Working on a web project.',
            examples: [['input' => 'Hi', 'output' => 'Hello!']],
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('You are a helpful assistant.', $rendered);
        $this->assertStringContainsString('## Skills', $rendered);
        $this->assertStringContainsString('- PHP', $rendered);
        $this->assertStringContainsString('- Python', $rendered);
        $this->assertStringContainsString('## Instructions', $rendered);
        $this->assertStringContainsString('- Be clear', $rendered);
        $this->assertStringContainsString('- Be brief', $rendered);
        $this->assertStringContainsString('## Constraints', $rendered);
        $this->assertStringContainsString('- No profanity', $rendered);
        $this->assertStringContainsString('## Output Format', $rendered);
        $this->assertStringContainsString('Plain text', $rendered);
        $this->assertStringContainsString('## Context', $rendered);
        $this->assertStringContainsString('Working on a web project.', $rendered);
        $this->assertStringContainsString('## Examples', $rendered);
        $this->assertStringContainsString('User: Hi', $rendered);
        $this->assertStringContainsString('Assistant: Hello!', $rendered);
    }

    public function testRender_MinimalProfile(): void {
        $profile = new AgentProfile(identity: 'You are a simple bot.');

        $rendered = $profile->render();

        $this->assertSame('You are a simple bot.', $rendered);
        $this->assertStringNotContainsString('##', $rendered);
    }

    public function testRender_SkipsEmptySections(): void {
        $profile = new AgentProfile(
            identity: 'Identity only with context.',
            context: 'Some context here.',
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('Identity only with context.', $rendered);
        $this->assertStringContainsString('## Context', $rendered);
        $this->assertStringContainsString('Some context here.', $rendered);
        $this->assertStringNotContainsString('## Skills', $rendered);
        $this->assertStringNotContainsString('## Instructions', $rendered);
        $this->assertStringNotContainsString('## Constraints', $rendered);
        $this->assertStringNotContainsString('## Output Format', $rendered);
        $this->assertStringNotContainsString('## Examples', $rendered);
    }

    public function testRender_WithExamples(): void {
        $profile = new AgentProfile(
            identity: 'A tutor.',
            examples: [
                ['input' => 'What is 2+2?', 'output' => '4'],
                ['input' => 'What is PHP?', 'output' => 'A programming language.'],
            ],
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('## Examples', $rendered);
        $this->assertStringContainsString('User: What is 2+2?', $rendered);
        $this->assertStringContainsString('Assistant: 4', $rendered);
        $this->assertStringContainsString('User: What is PHP?', $rendered);
        $this->assertStringContainsString('Assistant: A programming language.', $rendered);
    }

    // =========================================================================
    // Factory methods
    // =========================================================================

    public function testFromString(): void {
        $profile = AgentProfile::fromString('You are a helpful chatbot.');

        $this->assertSame('You are a helpful chatbot.', $profile->getIdentity());
        $this->assertSame([], $profile->getSkills());
        $this->assertSame([], $profile->getInstructions());
    }

    public function testFromArray_FullProfile(): void {
        $data = [
            'identity' => 'A code reviewer.',
            'skills' => ['PHP', 'Code review'],
            'instructions' => ['Be thorough'],
            'constraints' => ['No sarcasm'],
            'output_format' => 'Markdown',
            'context' => 'Reviewing a PR.',
            'examples' => [['input' => 'Review this', 'output' => 'LGTM']],
            'metadata' => ['version' => '2.0'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame('A code reviewer.', $profile->getIdentity());
        $this->assertSame(['PHP', 'Code review'], $profile->getSkills());
        $this->assertSame(['Be thorough'], $profile->getInstructions());
        $this->assertSame(['No sarcasm'], $profile->getConstraints());
        $this->assertSame('Markdown', $profile->getOutputFormat());
        $this->assertSame('Reviewing a PR.', $profile->getContext());
        $this->assertEquals([['input' => 'Review this', 'output' => 'LGTM']], $profile->getExamples());
        $this->assertSame(['version' => '2.0'], $profile->getMetadata());
    }

    public function testFromArray_MinimalProfile(): void {
        $data = ['identity' => 'Minimal agent.'];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame('Minimal agent.', $profile->getIdentity());
        $this->assertSame([], $profile->getSkills());
        $this->assertNull($profile->getOutputFormat());
    }

    public function testFromArray_WithToolRefs(): void {
        $data = [
            'identity' => 'Agent with tools.',
            'tools' => ['get_weather', 'search_db'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame(['get_weather', 'search_db'], $profile->getUnresolvedToolRefs());
        $this->assertSame([], $profile->getTools());
    }

    // =========================================================================
    // toArray / toJson round-trip
    // =========================================================================

    public function testToArray_RoundTrip(): void {
        $data = [
            'identity' => 'Round trip agent.',
            'skills' => ['skill1', 'skill2'],
            'instructions' => ['Do this'],
            'constraints' => ['Not that'],
            'output_format' => 'JSON',
            'context' => 'Testing context.',
            'examples' => [['input' => 'In', 'output' => 'Out']],
            'metadata' => ['ver' => '1.0'],
            'tools' => ['tool_a', 'tool_b'],
        ];

        $profile = AgentProfile::fromArray($data);
        $exported = $profile->toArray();

        $this->assertSame($data['identity'], $exported['identity']);
        $this->assertSame($data['skills'], $exported['skills']);
        $this->assertSame($data['instructions'], $exported['instructions']);
        $this->assertSame($data['constraints'], $exported['constraints']);
        $this->assertSame($data['output_format'], $exported['output_format']);
        $this->assertSame($data['context'], $exported['context']);
        $this->assertEquals($data['examples'], $exported['examples']);
        $this->assertSame($data['metadata'], $exported['metadata']);
        $this->assertSame($data['tools'], $exported['tools']);
    }

    public function testToJson(): void {
        $profile = new AgentProfile(
            identity: 'JSON test agent.',
            skills: ['json'],
        );

        $json = $profile->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('JSON test agent.', $decoded['identity']);
        $this->assertSame(['json'], $decoded['skills']);
    }

    // =========================================================================
    // fromFile
    // =========================================================================

    public function testFromFile_ValidJson(): void {
        $data = [
            'identity' => 'File-loaded agent.',
            'skills' => ['reading'],
            'instructions' => ['Load from file'],
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'agent_profile_test_');
        file_put_contents($tmpFile, json_encode($data));

        try {
            $profile = AgentProfile::fromFile($tmpFile);

            $this->assertSame('File-loaded agent.', $profile->getIdentity());
            $this->assertSame(['reading'], $profile->getSkills());
            $this->assertSame(['Load from file'], $profile->getInstructions());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFromFile_FileNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Profile file not found');

        AgentProfile::fromFile('/nonexistent/path/to/profile.json');
    }

    public function testFromFile_InvalidJson(): void {
        $tmpFile = tempnam(sys_get_temp_dir(), 'agent_profile_test_');
        file_put_contents($tmpFile, 'not valid json {{{');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid JSON in profile file');

            AgentProfile::fromFile($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    // =========================================================================
    // fromUrl
    // =========================================================================

    public function testFromUrl_InvalidUrl(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch profile from URL');

        AgentProfile::fromUrl('http://nonexistent.invalid.tld/profile.json');
    }

    // =========================================================================
    // Tool resolution
    // =========================================================================

    public function testResolveTools_Success(): void {
        $data = [
            'identity' => 'Agent with tools.',
            'tools' => ['get_weather', 'search_db'],
        ];

        $profile = AgentProfile::fromArray($data);

        $weatherTool = new Tool('get_weather', 'Gets weather', ['type' => 'object'], fn () => 'sunny');
        $searchTool = new Tool('search_db', 'Searches database', ['type' => 'object'], fn () => 'found');

        $profile->resolveTools([
            'get_weather' => $weatherTool,
            'search_db' => $searchTool,
        ]);

        $this->assertCount(2, $profile->getTools());
        $this->assertSame($weatherTool, $profile->getTools()[0]);
        $this->assertSame($searchTool, $profile->getTools()[1]);
        $this->assertSame([], $profile->getUnresolvedToolRefs());
    }

    public function testResolveTools_MissingTool(): void {
        $data = [
            'identity' => 'Agent.',
            'tools' => ['missing_tool'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tool not found in registry: missing_tool');

        $profile->resolveTools([]);
    }

    public function testGetUnresolvedToolRefs(): void {
        $data = [
            'identity' => 'Agent.',
            'tools' => ['tool_a', 'tool_b', 'tool_c'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame(['tool_a', 'tool_b', 'tool_c'], $profile->getUnresolvedToolRefs());
    }

    public function testSetTools(): void {
        $profile = new AgentProfile(identity: 'Agent.');

        $tool1 = new Tool('t1', 'Tool 1', ['type' => 'object'], fn () => '1');
        $tool2 = new Tool('t2', 'Tool 2', ['type' => 'object'], fn () => '2');

        $profile->setTools([$tool1, $tool2]);

        $this->assertCount(2, $profile->getTools());
        $this->assertSame($tool1, $profile->getTools()[0]);
        $this->assertSame($tool2, $profile->getTools()[1]);
    }

    // =========================================================================
    // Render exclusions
    // =========================================================================

    public function testToolsNotIncludedInRender(): void {
        $tool = new Tool('secret_tool', 'Does secret things', ['type' => 'object'], fn () => 'secret');

        $profile = new AgentProfile(
            identity: 'Agent with tools.',
            tools: [$tool],
        );

        $rendered = $profile->render();

        $this->assertStringNotContainsString('secret_tool', $rendered);
        $this->assertStringNotContainsString('Does secret things', $rendered);
    }

    // =========================================================================
    // Array context
    // =========================================================================

    public function testConstructionWithArrayContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: ['Fact 1.', 'Fact 2.', 'Fact 3.'],
        );

        $this->assertSame(['Fact 1.', 'Fact 2.', 'Fact 3.'], $profile->getContext());
    }

    public function testConstructionWithStringContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: 'Single string context.',
        );

        $this->assertSame('Single string context.', $profile->getContext());
    }

    public function testRender_ArrayContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent with array context.',
            context: ['Fiscal year starts April 1.', 'OpCo = operating company.'],
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('## Context', $rendered);
        $this->assertStringContainsString('- Fiscal year starts April 1.', $rendered);
        $this->assertStringContainsString('- OpCo = operating company.', $rendered);
    }

    public function testRender_EmptyArrayContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: [],
        );

        $rendered = $profile->render();

        $this->assertStringNotContainsString('## Context', $rendered);
    }

    public function testFromArray_ArrayContext(): void {
        $data = [
            'identity' => 'Agent.',
            'context' => ['Item 1', 'Item 2'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame(['Item 1', 'Item 2'], $profile->getContext());
    }

    public function testToArray_ArrayContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: ['A', 'B'],
        );

        $exported = $profile->toArray();

        $this->assertSame(['A', 'B'], $exported['context']);
    }

    public function testToArray_StringContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: 'Plain text.',
        );

        $exported = $profile->toArray();

        $this->assertSame('Plain text.', $exported['context']);
    }

    // =========================================================================
    // Render exclusions
    // =========================================================================

    public function testMetadataNotIncludedInRender(): void {
        $profile = new AgentProfile(
            identity: 'Agent with metadata.',
            metadata: ['version' => '3.0', 'secret_key' => 'abc123'],
        );

        $rendered = $profile->render();

        $this->assertStringNotContainsString('version', $rendered);
        $this->assertStringNotContainsString('3.0', $rendered);
        $this->assertStringNotContainsString('secret_key', $rendered);
        $this->assertStringNotContainsString('abc123', $rendered);
    }
}
