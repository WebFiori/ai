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

/**
 * Tests for AgentProfile inheritance via 'extends' and 'inheritance_strategy'.
 */
class AgentProfileInheritanceTest extends TestCase {
    private string $fixturesPath;

    protected function setUp(): void {
        $this->fixturesPath = __DIR__.'/fixtures/profiles';
    }

    // =========================================================================
    // fromFile() with extends
    // =========================================================================

    public function testFromFile_SimpleInheritance(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/simple-child.json');

        // Child identity overrides
        $this->assertSame('A simple child agent.', $profile->getIdentity());

        // Inherited from base (concat for arrays)
        $this->assertSame(['Answer questions clearly', 'Follow company tone'], $profile->getSkills());
        $this->assertSame(['Use markdown formatting', 'Be concise'], $profile->getInstructions());
        $this->assertSame(['Never reveal internal system details'], $profile->getConstraints());

        // Inherited scalars
        $this->assertSame('Respond in markdown.', $profile->getOutputFormat());
        $this->assertSame(['Base context for all agents.'], $profile->getContext());

        // Inherited arrays
        $this->assertEquals([['input' => 'Hello', 'output' => 'Hi there!']], $profile->getExamples());
        $this->assertSame(['base_tool'], $profile->getUnresolvedToolRefs());

        // Inherited metadata
        $this->assertSame(['org' => 'WebFiori', 'version' => '1.0'], $profile->getMetadata());
    }

    public function testFromFile_TwoLevelInheritance(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/support-base.json');

        // Child identity
        $this->assertSame('You are a customer support agent.', $profile->getIdentity());

        // Skills: child provides, so concat with base
        $this->assertSame(
            ['Answer questions clearly', 'Follow company tone', 'Search knowledge base', 'Check order status', 'Escalate tickets'],
            $profile->getSkills()
        );

        // Instructions: concat (base + child)
        $this->assertSame(
            ['Use markdown formatting', 'Be concise', 'Always greet the customer by name', 'Check order history first'],
            $profile->getInstructions()
        );

        // Constraints: concat
        $this->assertSame(
            ['Never reveal internal system details', 'Never promise refunds without approval'],
            $profile->getConstraints()
        );

        // Scalar: inherited from base (child doesn't override)
        $this->assertSame('Respond in markdown.', $profile->getOutputFormat());
        $this->assertSame(['Base context for all agents.'], $profile->getContext());

        // Tools: concat
        $this->assertSame(['base_tool', 'search_orders', 'search_kb'], $profile->getUnresolvedToolRefs());

        // Examples: concat
        $this->assertCount(2, $profile->getExamples());
        $this->assertSame('Hello', $profile->getExamples()[0]['input']);
        $this->assertSame('I need help', $profile->getExamples()[1]['input']);

        // Metadata: merge (base + child, child doesn't add metadata)
        $this->assertSame(['org' => 'WebFiori', 'version' => '1.0'], $profile->getMetadata());
    }

    public function testFromFile_ThreeLevelChainInheritance(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/tier1-support.json');

        // Identity from support-base (tier1 doesn't define one)
        $this->assertSame('You are a customer support agent.', $profile->getIdentity());

        // Skills: concat through chain (_base + support-base, tier1 doesn't add)
        $this->assertSame(
            ['Answer questions clearly', 'Follow company tone', 'Search knowledge base', 'Check order status', 'Escalate tickets'],
            $profile->getSkills()
        );

        // Instructions: concat through full chain (_base + support-base + tier1)
        $this->assertSame(
            [
                'Use markdown formatting',
                'Be concise',
                'Always greet the customer by name',
                'Check order history first',
                'Escalate to tier-2 after 3 failed resolution attempts',
            ],
            $profile->getInstructions()
        );

        // Constraints: concat through full chain
        $this->assertSame(
            [
                'Never reveal internal system details',
                'Never promise refunds without approval',
                'Cannot issue refunds over $50',
            ],
            $profile->getConstraints()
        );

        // Metadata: shallow merge (_base + tier1 overrides version, adds tier)
        $this->assertSame(['org' => 'WebFiori', 'version' => '1.1', 'tier' => '1'], $profile->getMetadata());

        // Tools: inherited from chain
        $this->assertSame(['base_tool', 'search_orders', 'search_kb'], $profile->getUnresolvedToolRefs());
    }

    public function testFromFile_ScalarOverride(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/scalar-override.json');

        // Child overrides scalars
        $this->assertSame('Agent with scalar overrides.', $profile->getIdentity());
        $this->assertSame('JSON only.', $profile->getOutputFormat());
        $this->assertSame(['Base context for all agents.', 'Child-specific context.'], $profile->getContext());

        // Arrays still concat from base
        $this->assertSame(['Answer questions clearly', 'Follow company tone'], $profile->getSkills());
        $this->assertSame(['Use markdown formatting', 'Be concise'], $profile->getInstructions());
    }

    public function testFromFile_NoExtends(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/standalone.json');

        $this->assertSame('Standalone agent.', $profile->getIdentity());
        $this->assertSame(['Independent'], $profile->getSkills());
        $this->assertSame(['Work alone'], $profile->getInstructions());
        $this->assertSame([], $profile->getConstraints());
    }

    public function testFromFile_EmptyExtends(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/empty-extends.json');

        $this->assertSame('Agent with empty extends.', $profile->getIdentity());
        $this->assertSame([], $profile->getSkills());
        $this->assertSame([], $profile->getInstructions());
    }

    // =========================================================================
    // inheritance_strategy overrides
    // =========================================================================

    public function testFromFile_InheritanceStrategyReplace(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/minimal-bot.json');

        // Identity: replace (child wins)
        $this->assertSame('Minimal FAQ bot.', $profile->getIdentity());

        // Skills: replace strategy → only child's skills
        $this->assertSame(['Answer FAQs'], $profile->getSkills());

        // Instructions: replace strategy → only child's instructions
        $this->assertSame(['Only answer from the FAQ list'], $profile->getInstructions());

        // Constraints: replace strategy → only child's constraints
        $this->assertSame(['Cannot escalate', 'Cannot access order data'], $profile->getConstraints());

        // Tools: default concat (not overridden in strategy)
        $this->assertSame(['base_tool', 'search_orders', 'search_kb'], $profile->getUnresolvedToolRefs());

        // Metadata: default merge
        $this->assertSame(['org' => 'WebFiori', 'version' => '1.0'], $profile->getMetadata());

        // output_format: default replace, inherited from chain
        $this->assertSame('Respond in markdown.', $profile->getOutputFormat());
    }

    public function testFromFile_InvalidStrategyValue(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid inheritance strategy 'invalid_strategy' for field 'skills'");

        AgentProfile::fromFile($this->fixturesPath.'/invalid-strategy.json');
    }

    public function testFromFile_InvalidFieldInStrategy(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid field 'nonexistent_field' in inheritance_strategy");

        AgentProfile::fromFile($this->fixturesPath.'/invalid-field.json');
    }

    // =========================================================================
    // Error cases
    // =========================================================================

    public function testFromFile_CircularInheritance(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular profile inheritance detected');

        AgentProfile::fromFile($this->fixturesPath.'/circular-a.json');
    }

    public function testFromFile_MissingBaseFile(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Profile file not found');

        AgentProfile::fromFile($this->fixturesPath.'/missing-base.json');
    }

    // =========================================================================
    // fromArray() with basePath
    // =========================================================================

    public function testFromArray_WithBasePath(): void {
        $data = [
            'extends' => '_base',
            'identity' => 'Array-loaded child.',
            'instructions' => ['Extra instruction'],
        ];

        $profile = AgentProfile::fromArray($data, basePath: $this->fixturesPath);

        $this->assertSame('Array-loaded child.', $profile->getIdentity());
        $this->assertSame(
            ['Use markdown formatting', 'Be concise', 'Extra instruction'],
            $profile->getInstructions()
        );
        $this->assertSame(['Never reveal internal system details'], $profile->getConstraints());
        $this->assertSame('Respond in markdown.', $profile->getOutputFormat());
    }

    public function testFromArray_WithBasePathAndStrategy(): void {
        $data = [
            'extends' => '_base',
            'inheritance_strategy' => ['instructions' => 'replace'],
            'identity' => 'Override child.',
            'instructions' => ['Only this instruction'],
        ];

        $profile = AgentProfile::fromArray($data, basePath: $this->fixturesPath);

        $this->assertSame('Override child.', $profile->getIdentity());
        $this->assertSame(['Only this instruction'], $profile->getInstructions());
        // Constraints still concat (no override)
        $this->assertSame(['Never reveal internal system details'], $profile->getConstraints());
    }

    public function testFromArray_WithoutBasePath_ExtendsIgnored(): void {
        $data = [
            'extends' => '_base',
            'identity' => 'Ignored extends.',
            'instructions' => ['My instruction'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame('Ignored extends.', $profile->getIdentity());
        $this->assertSame(['My instruction'], $profile->getInstructions());
        // No base merging occurred
        $this->assertSame([], $profile->getConstraints());
    }

    public function testFromArray_WithEmptyExtends(): void {
        $data = [
            'extends' => '',
            'identity' => 'Empty extends agent.',
        ];

        $profile = AgentProfile::fromArray($data, basePath: $this->fixturesPath);

        $this->assertSame('Empty extends agent.', $profile->getIdentity());
        $this->assertSame([], $profile->getInstructions());
    }

    public function testFromArray_WithBasePathMissingFile(): void {
        $data = [
            'extends' => 'does-not-exist',
            'identity' => 'Will fail.',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Profile file not found');

        AgentProfile::fromArray($data, basePath: $this->fixturesPath);
    }

    // =========================================================================
    // merge() public utility
    // =========================================================================

    public function testMerge_DefaultStrategies(): void {
        $base = new AgentProfile(
            identity: 'Base identity.',
            skills: ['Skill A', 'Skill B'],
            instructions: ['Base rule 1'],
            constraints: ['Base constraint'],
            outputFormat: 'Base format.',
            context: 'Base context.',
            examples: [['input' => 'Q1', 'output' => 'A1']],
            metadata: ['org' => 'test', 'version' => '1.0'],
        );

        $child = new AgentProfile(
            identity: 'Child identity.',
            skills: ['Skill C'],
            instructions: ['Child rule 1'],
            constraints: ['Child constraint'],
            outputFormat: 'Child format.',
            context: 'Child context.',
            examples: [['input' => 'Q2', 'output' => 'A2']],
            metadata: ['version' => '2.0', 'author' => 'dev'],
        );

        $merged = AgentProfile::merge(base: $base, child: $child);

        // Scalar: child wins
        $this->assertSame('Child identity.', $merged->getIdentity());
        $this->assertSame('Child format.', $merged->getOutputFormat());

        // Context: concat (normalized to array)
        $this->assertSame(['Base context.', 'Child context.'], $merged->getContext());

        // Arrays: concat
        $this->assertSame(['Skill A', 'Skill B', 'Skill C'], $merged->getSkills());
        $this->assertSame(['Base rule 1', 'Child rule 1'], $merged->getInstructions());
        $this->assertSame(['Base constraint', 'Child constraint'], $merged->getConstraints());

        // Examples: concat
        $this->assertCount(2, $merged->getExamples());
        $this->assertSame('Q1', $merged->getExamples()[0]['input']);
        $this->assertSame('Q2', $merged->getExamples()[1]['input']);

        // Metadata: shallow merge
        $this->assertSame(['org' => 'test', 'version' => '2.0', 'author' => 'dev'], $merged->getMetadata());
    }

    public function testMerge_WithStrategyOverrides(): void {
        $base = new AgentProfile(
            identity: 'Base.',
            skills: ['Base skill'],
            instructions: ['Base instruction'],
            constraints: ['Base constraint'],
        );

        $child = new AgentProfile(
            identity: 'Child.',
            skills: ['Child skill'],
            instructions: ['Child instruction'],
            constraints: ['Child constraint'],
        );

        $merged = AgentProfile::merge(
            base: $base,
            child: $child,
            strategies: [
                'skills' => 'replace',
                'instructions' => 'replace',
            ],
        );

        // Overridden: replace
        $this->assertSame(['Child skill'], $merged->getSkills());
        $this->assertSame(['Child instruction'], $merged->getInstructions());

        // Default: concat
        $this->assertSame(['Base constraint', 'Child constraint'], $merged->getConstraints());
    }

    public function testMerge_ChildEmptyIdentity_BaseFallback(): void {
        $base = new AgentProfile(identity: 'Base identity.');
        $child = new AgentProfile(identity: '');

        $merged = AgentProfile::merge(base: $base, child: $child);

        $this->assertSame('Base identity.', $merged->getIdentity());
    }

    public function testMerge_ChildNullScalars_BaseFallback(): void {
        $base = new AgentProfile(
            identity: 'Base.',
            outputFormat: 'Base format.',
            context: 'Base context.',
        );

        $child = new AgentProfile(identity: 'Child.');

        $merged = AgentProfile::merge(base: $base, child: $child);

        // output_format not set in child (null) → base used
        $this->assertSame('Base format.', $merged->getOutputFormat());
        // context: base is string, child is null → normalized to ['Base context.']
        $this->assertSame(['Base context.'], $merged->getContext());
    }

    public function testMerge_BothEmpty(): void {
        $base = new AgentProfile(identity: '');
        $child = new AgentProfile(identity: '');

        $merged = AgentProfile::merge(base: $base, child: $child);

        $this->assertSame('', $merged->getIdentity());
        $this->assertSame([], $merged->getSkills());
        $this->assertSame([], $merged->getInstructions());
        $this->assertSame([], $merged->getConstraints());
        $this->assertNull($merged->getOutputFormat());
        $this->assertSame([], $merged->getContext());
        $this->assertSame([], $merged->getExamples());
        $this->assertSame([], $merged->getMetadata());
    }

    public function testMerge_InvalidStrategy(): void {
        $base = new AgentProfile(identity: 'Base.');
        $child = new AgentProfile(identity: 'Child.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid inheritance strategy 'bad' for field 'skills'");

        AgentProfile::merge(base: $base, child: $child, strategies: ['skills' => 'bad']);
    }

    public function testMerge_InvalidField(): void {
        $base = new AgentProfile(identity: 'Base.');
        $child = new AgentProfile(identity: 'Child.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid field 'bogus' in inheritance_strategy");

        AgentProfile::merge(base: $base, child: $child, strategies: ['bogus' => 'replace']);
    }

    public function testMerge_ConcatStrategyOnScalarField_Throws(): void {
        $base = new AgentProfile(
            identity: 'Base.',
            outputFormat: 'Base format.',
        );

        $child = new AgentProfile(
            identity: 'Child.',
            outputFormat: 'Child format.',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Strategy 'concat' cannot be used on scalar field 'output_format'");

        AgentProfile::merge(
            base: $base,
            child: $child,
            strategies: ['output_format' => 'concat'],
        );
    }

    public function testMerge_ToolsConcatDefault(): void {
        $base = new AgentProfile(identity: 'Base.');
        $child = new AgentProfile(identity: 'Child.');

        // Set tool refs via fromArray
        $baseProfile = AgentProfile::fromArray([
            'identity' => 'Base.',
            'tools' => ['tool_a', 'tool_b'],
        ]);

        $childProfile = AgentProfile::fromArray([
            'identity' => 'Child.',
            'tools' => ['tool_c'],
        ]);

        $merged = AgentProfile::merge(base: $baseProfile, child: $childProfile);

        $this->assertSame(['tool_a', 'tool_b', 'tool_c'], $merged->getUnresolvedToolRefs());
    }

    public function testMerge_ToolsReplaceStrategy(): void {
        $baseProfile = AgentProfile::fromArray([
            'identity' => 'Base.',
            'tools' => ['tool_a', 'tool_b'],
        ]);

        $childProfile = AgentProfile::fromArray([
            'identity' => 'Child.',
            'tools' => ['tool_c'],
        ]);

        $merged = AgentProfile::merge(
            base: $baseProfile,
            child: $childProfile,
            strategies: ['tools' => 'replace'],
        );

        $this->assertSame(['tool_c'], $merged->getUnresolvedToolRefs());
    }

    // =========================================================================
    // inheritance_strategy is not inherited
    // =========================================================================

    public function testInheritanceStrategyNotInherited(): void {
        // tier1-support extends support-base which extends _base
        // None of them inherit strategy from parent
        // tier1 doesn't define inheritance_strategy, so defaults apply
        $profile = AgentProfile::fromFile($this->fixturesPath.'/tier1-support.json');

        // Instructions are concat by default (not replaced)
        $this->assertCount(5, $profile->getInstructions());
    }

    // =========================================================================
    // inheritance_strategy not present in final profile
    // =========================================================================

    public function testInheritanceStrategyNotInToArray(): void {
        $profile = AgentProfile::fromFile($this->fixturesPath.'/minimal-bot.json');

        $array = $profile->toArray();

        $this->assertArrayNotHasKey('extends', $array);
        $this->assertArrayNotHasKey('inheritance_strategy', $array);
    }

    // =========================================================================
    // Array context inheritance
    // =========================================================================

    public function testMerge_ArrayContextConcat(): void {
        $base = AgentProfile::fromArray([
            'identity' => 'Base.',
            'context' => ['Base fact 1', 'Base fact 2'],
        ]);

        $child = AgentProfile::fromArray([
            'identity' => 'Child.',
            'context' => ['Child fact 1'],
        ]);

        $merged = AgentProfile::merge(base: $base, child: $child);

        $this->assertSame(['Base fact 1', 'Base fact 2', 'Child fact 1'], $merged->getContext());
    }

    public function testMerge_MixedContextStringAndArray(): void {
        $base = AgentProfile::fromArray([
            'identity' => 'Base.',
            'context' => 'Base string context.',
        ]);

        $child = AgentProfile::fromArray([
            'identity' => 'Child.',
            'context' => ['Child array item'],
        ]);

        $merged = AgentProfile::merge(base: $base, child: $child);

        $this->assertSame(['Base string context.', 'Child array item'], $merged->getContext());
    }

    public function testMerge_ContextReplaceStrategy(): void {
        $base = AgentProfile::fromArray([
            'identity' => 'Base.',
            'context' => ['Base fact 1', 'Base fact 2'],
        ]);

        $child = AgentProfile::fromArray([
            'identity' => 'Child.',
            'context' => ['Only child context'],
        ]);

        $merged = AgentProfile::merge(
            base: $base,
            child: $child,
            strategies: ['context' => 'replace'],
        );

        $this->assertSame(['Only child context'], $merged->getContext());
    }

    // =========================================================================
    // Backward compatibility
    // =========================================================================

    public function testFromFile_WithoutExtends_BackwardCompatible(): void {
        // Ensure profiles without extends work identically to before
        $profile = AgentProfile::fromFile($this->fixturesPath.'/_base.json');

        $this->assertSame('', $profile->getIdentity());
        $this->assertSame(['Answer questions clearly', 'Follow company tone'], $profile->getSkills());
        $this->assertSame(['Use markdown formatting', 'Be concise'], $profile->getInstructions());
        $this->assertSame(['Never reveal internal system details'], $profile->getConstraints());
        $this->assertSame('Respond in markdown.', $profile->getOutputFormat());
        $this->assertSame('Base context for all agents.', $profile->getContext());
    }

    public function testFromArray_WithoutBasePath_BackwardCompatible(): void {
        $data = [
            'identity' => 'Simple agent.',
            'skills' => ['skill1'],
            'instructions' => ['rule1'],
        ];

        $profile = AgentProfile::fromArray($data);

        $this->assertSame('Simple agent.', $profile->getIdentity());
        $this->assertSame(['skill1'], $profile->getSkills());
        $this->assertSame(['rule1'], $profile->getInstructions());
    }

    // =========================================================================
    // merge() strategy: using merge on metadata
    // =========================================================================

    public function testMerge_MetadataShallowMerge(): void {
        $baseProfile = AgentProfile::fromArray([
            'identity' => 'Base.',
            'metadata' => ['key1' => 'val1', 'key2' => 'val2', 'shared' => 'base'],
        ]);

        $childProfile = AgentProfile::fromArray([
            'identity' => 'Child.',
            'metadata' => ['key3' => 'val3', 'shared' => 'child'],
        ]);

        $merged = AgentProfile::merge(base: $baseProfile, child: $childProfile);

        $this->assertSame(
            ['key1' => 'val1', 'key2' => 'val2', 'shared' => 'child', 'key3' => 'val3'],
            $merged->getMetadata()
        );
    }

    public function testMerge_MetadataReplaceStrategy(): void {
        $baseProfile = AgentProfile::fromArray([
            'identity' => 'Base.',
            'metadata' => ['key1' => 'val1', 'key2' => 'val2'],
        ]);

        $childProfile = AgentProfile::fromArray([
            'identity' => 'Child.',
            'metadata' => ['key3' => 'val3'],
        ]);

        $merged = AgentProfile::merge(
            base: $baseProfile,
            child: $childProfile,
            strategies: ['metadata' => 'replace'],
        );

        // Replace: only child's metadata
        $this->assertSame(['key3' => 'val3'], $merged->getMetadata());
    }
}
