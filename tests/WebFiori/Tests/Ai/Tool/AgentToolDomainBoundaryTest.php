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
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\DomainBoundaries;

/**
 * Tests for domain-boundary enforcement in AgentTool and AgentProfile.
 */
class AgentToolDomainBoundaryTest extends TestCase {
    // =========================================================================
    // AgentTool enforcement
    // =========================================================================

    public function testExecute_OffTopicTask_ReturnsRedirectWithoutCallingProvider(): void {
        $provider = new CapturingMockProvider();
        $profile = new AgentProfile(
            identity: 'You are Business AI.',
            skills: ['Sales', 'Stock'],
            domainBoundaries: new DomainBoundaries(
                blockPatterns: ['weather|lyrics'],
                redirectTemplate: "That's outside my focus.",
            ),
        );
        $tool = new AgentTool('business_ai', 'Business assistant', $provider, $profile);

        $result = $tool->execute(['task' => 'What is the weather tomorrow?']);

        $this->assertSame("That's outside my focus.", $result);
        // The provider must NOT be called — the whole point of the guardrail.
        $this->assertSame(0, $provider->callCount);
    }

    public function testExecute_InScopeTask_ProceedsToProvider(): void {
        $provider = new CapturingMockProvider('mock', 'Sales were up 12%.');
        $profile = new AgentProfile(
            identity: 'You are Business AI.',
            domainBoundaries: new DomainBoundaries(
                blockPatterns: ['weather|lyrics'],
                redirectTemplate: "That's outside my focus.",
            ),
        );
        $tool = new AgentTool('business_ai', 'Business assistant', $provider, $profile);

        $result = $tool->execute(['task' => 'What were our sales last quarter?']);

        $this->assertSame('Sales were up 12%.', $result);
        $this->assertSame(1, $provider->callCount);
    }

    public function testExecute_NoBoundaries_ProceedsAsBefore(): void {
        $provider = new CapturingMockProvider('mock', 'Answer.');
        $tool = new AgentTool('agent', 'Desc', $provider, 'You are helpful.');

        $result = $tool->execute(['task' => 'Anything at all']);

        $this->assertSame('Answer.', $result);
        $this->assertSame(1, $provider->callCount);
    }

    public function testExecute_ShadowMode_ProceedsToProvider(): void {
        $provider = new CapturingMockProvider('mock', 'Real answer.');
        $profile = new AgentProfile(
            identity: 'You are Business AI.',
            domainBoundaries: new DomainBoundaries(
                blockPatterns: ['weather'],
                redirectTemplate: "That's outside my focus.",
                logOnly: true,
            ),
        );
        $tool = new AgentTool('business_ai', 'Business assistant', $provider, $profile);

        // Shadow mode: would-be blocked, but request proceeds.
        $result = $tool->execute(['task' => 'What is the weather?']);

        $this->assertSame('Real answer.', $result);
        $this->assertSame(1, $provider->callCount);
    }

    // =========================================================================
    // AgentProfile serialization
    // =========================================================================

    public function testProfile_GetDomainBoundaries_NullByDefault(): void {
        $profile = new AgentProfile(identity: 'Agent.');

        $this->assertNull($profile->getDomainBoundaries());
    }

    public function testProfile_FromArray_ParsesDomainBoundaries(): void {
        $profile = AgentProfile::fromArray([
            'identity' => 'You are Business AI.',
            'domain_boundaries' => [
                'strategy' => 'regex',
                'block_patterns' => ['weather'],
                'redirect_template' => 'Redirect.',
            ],
        ]);

        $boundaries = $profile->getDomainBoundaries();

        $this->assertInstanceOf(DomainBoundaries::class, $boundaries);
        $this->assertSame(['weather'], $boundaries->getBlockPatterns());
        $this->assertSame('Redirect.', $boundaries->getRedirectTemplate());
    }

    public function testProfile_FromArray_NoDomainBoundaries_Null(): void {
        $profile = AgentProfile::fromArray(['identity' => 'Agent.']);

        $this->assertNull($profile->getDomainBoundaries());
    }

    public function testProfile_ToArray_EmitsDomainBoundaries(): void {
        $profile = new AgentProfile(
            identity: 'Agent.',
            domainBoundaries: new DomainBoundaries(
                blockPatterns: ['weather'],
                redirectTemplate: 'Redirect.',
            ),
        );

        $data = $profile->toArray();

        $this->assertArrayHasKey('domain_boundaries', $data);
        $this->assertSame(['weather'], $data['domain_boundaries']['block_patterns']);
    }

    public function testProfile_ToArray_OmitsWhenNull(): void {
        $profile = new AgentProfile(identity: 'Agent.');

        $this->assertArrayNotHasKey('domain_boundaries', $profile->toArray());
    }

    public function testProfile_RoundTrip(): void {
        $profile = new AgentProfile(
            identity: 'You are Business AI.',
            domainBoundaries: new DomainBoundaries(
                inScope: ['sales'],
                blockPatterns: ['weather'],
                redirectTemplate: 'Redirect.',
                strategy: 'regex',
            ),
        );

        $restored = AgentProfile::fromArray($profile->toArray());

        $this->assertNotNull($restored->getDomainBoundaries());
        $this->assertSame(
            $profile->getDomainBoundaries()->toArray(),
            $restored->getDomainBoundaries()->toArray()
        );
    }

    // =========================================================================
    // Inheritance: domain_boundaries defaults to 'replace'
    // =========================================================================

    public function testProfile_Merge_ChildBoundariesReplaceParent(): void {
        $base = new AgentProfile(
            identity: 'Base.',
            domainBoundaries: new DomainBoundaries(blockPatterns: ['weather']),
        );
        $child = new AgentProfile(
            identity: 'Child.',
            domainBoundaries: new DomainBoundaries(blockPatterns: ['lyrics']),
        );

        $merged = AgentProfile::merge($base, $child);

        // Replace strategy: child's boundaries win wholesale.
        $this->assertSame(['lyrics'], $merged->getDomainBoundaries()->getBlockPatterns());
    }

    public function testProfile_Merge_ChildWithoutBoundaries_InheritsBase(): void {
        $base = new AgentProfile(
            identity: 'Base.',
            domainBoundaries: new DomainBoundaries(blockPatterns: ['weather']),
        );
        $child = new AgentProfile(identity: 'Child.');

        $merged = AgentProfile::merge($base, $child);

        // Replace falls back to base when child value is absent.
        $this->assertNotNull($merged->getDomainBoundaries());
        $this->assertSame(['weather'], $merged->getDomainBoundaries()->getBlockPatterns());
    }
}
