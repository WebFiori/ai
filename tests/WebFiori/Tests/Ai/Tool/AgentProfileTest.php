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
    // Array example output (authoring sugar → normalized to string)
    // =========================================================================

    public function testConstruction_ArrayExampleOutput_NormalizedToString(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            examples: [
                ['input' => 'Q', 'output' => ['Line 1', 'Line 2', 'Line 3']],
            ],
        );

        // Internal shape stays {input: string, output: string}.
        $this->assertSame("Line 1\nLine 2\nLine 3", $profile->getExamples()[0]['output']);
        $this->assertIsString($profile->getExamples()[0]['output']);
    }

    public function testConstruction_EmptyArrayExampleOutput(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            examples: [
                ['input' => 'Q', 'output' => []],
            ],
        );

        $this->assertSame('', $profile->getExamples()[0]['output']);
    }

    public function testConstruction_StringExampleOutput_Unchanged(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            examples: [
                ['input' => 'Q', 'output' => 'Single line output.'],
            ],
        );

        $this->assertSame('Single line output.', $profile->getExamples()[0]['output']);
    }
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

    // =========================================================================
    // Array output format
    // =========================================================================

    public function testConstructionWithArrayOutputFormat(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            outputFormat: ['Use markdown.', 'Include a code block.', 'End with a summary.'],
        );

        $this->assertSame(
            ['Use markdown.', 'Include a code block.', 'End with a summary.'],
            $profile->getOutputFormat()
        );
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

    public function testConstructionWithStringContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: 'Single string context.',
        );

        $this->assertSame('Single string context.', $profile->getContext());
    }

    public function testConstructionWithStringOutputFormat(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            outputFormat: 'Single string format.',
        );

        $this->assertSame('Single string format.', $profile->getOutputFormat());
    }

    public function testFromArray_ArrayContext(): void {
        $data = [
            'identity' => 'Agent.',
            'context' => ['Item 1', 'Item 2'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame(['Item 1', 'Item 2'], $profile->getContext());
    }

    public function testFromArray_ArrayExampleOutput_NormalizedToString(): void {
        $data = [
            'identity' => 'Agent.',
            'examples' => [
                ['input' => 'How?', 'output' => ['## Step 1', 'Do this.', '## Step 2', 'Do that.']],
            ],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame("## Step 1\nDo this.\n## Step 2\nDo that.", $profile->getExamples()[0]['output']);
    }

    public function testFromArray_ArrayOutputFormat(): void {
        $data = [
            'identity' => 'Agent.',
            'output_format' => ['Rule 1', 'Rule 2'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame(['Rule 1', 'Rule 2'], $profile->getOutputFormat());
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

    // =========================================================================
    // Factory methods
    // =========================================================================

    public function testFromString(): void {
        $profile = AgentProfile::fromString('You are a helpful chatbot.');

        $this->assertSame('You are a helpful chatbot.', $profile->getIdentity());
        $this->assertSame([], $profile->getSkills());
        $this->assertSame([], $profile->getInstructions());
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

    public function testGetUnresolvedToolRefs(): void {
        $data = [
            'identity' => 'Agent.',
            'tools' => ['tool_a', 'tool_b', 'tool_c'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame(['tool_a', 'tool_b', 'tool_c'], $profile->getUnresolvedToolRefs());
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

    public function testRender_ArrayExampleOutput_JoinedMultiline(): void {
        $profile = new AgentProfile(
            identity: 'A tutor.',
            examples: [
                ['input' => 'Explain', 'output' => ['Point one.', 'Point two.']],
            ],
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('## Examples', $rendered);
        $this->assertStringContainsString('User: Explain', $rendered);
        // Output is a single Assistant turn spanning multiple lines (no bullets injected).
        $this->assertStringContainsString("Assistant: Point one.\nPoint two.", $rendered);
        $this->assertStringNotContainsString('- Point one.', $rendered);
    }

    public function testRender_ArrayOutputFormat(): void {
        $profile = new AgentProfile(
            identity: 'Agent with array output format.',
            outputFormat: ['Respond in JSON.', 'Include a "status" field.'],
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('## Output Format', $rendered);
        $this->assertStringContainsString('- Respond in JSON.', $rendered);
        $this->assertStringContainsString('- Include a "status" field.', $rendered);
    }

    public function testRender_EmptyArrayContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: [],
        );

        $rendered = $profile->render();

        $this->assertStringNotContainsString('## Context', $rendered);
    }

    public function testRender_EmptyArrayOutputFormat(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            outputFormat: [],
        );

        $rendered = $profile->render();

        $this->assertStringNotContainsString('## Output Format', $rendered);
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

    public function testRender_StringOutputFormat(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            outputFormat: 'Plain markdown only.',
        );

        $rendered = $profile->render();

        $this->assertStringContainsString('## Output Format', $rendered);
        $this->assertStringContainsString('Plain markdown only.', $rendered);
        // A single string must not be rendered as a bullet list.
        $this->assertStringNotContainsString('- Plain markdown only.', $rendered);
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

    public function testSetTools(): void {
        $profile = new AgentProfile(identity: 'Agent.');

        $tool1 = new Tool('t1', 'Tool 1', ['type' => 'object'], fn () => '1');
        $tool2 = new Tool('t2', 'Tool 2', ['type' => 'object'], fn () => '2');

        $profile->setTools([$tool1, $tool2]);

        $this->assertCount(2, $profile->getTools());
        $this->assertSame($tool1, $profile->getTools()[0]);
        $this->assertSame($tool2, $profile->getTools()[1]);
    }

    public function testToArray_ArrayContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: ['A', 'B'],
        );

        $exported = $profile->toArray();

        $this->assertSame(['A', 'B'], $exported['context']);
    }

    public function testToArray_ArrayExampleOutput_ExportsJoinedString(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            examples: [
                ['input' => 'Q', 'output' => ['First', 'Second']],
            ],
        );

        $exported = $profile->toArray();

        // Canonical stored form is the joined string, not the authored array.
        $this->assertSame('First'."\n".'Second', $exported['examples'][0]['output']);
    }

    public function testToArray_ArrayOutputFormat(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            outputFormat: ['A', 'B'],
        );

        $exported = $profile->toArray();

        $this->assertSame(['A', 'B'], $exported['output_format']);
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

    public function testToArray_StringContext(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            context: 'Plain text.',
        );

        $exported = $profile->toArray();

        $this->assertSame('Plain text.', $exported['context']);
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
}
