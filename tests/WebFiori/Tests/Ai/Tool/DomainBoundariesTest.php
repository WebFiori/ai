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
use WebFiori\Ai\Tool\BoundaryDecision;
use WebFiori\Ai\Tool\DomainBoundaries;

/**
 * Tests for DomainBoundaries.
 */
class DomainBoundariesTest extends TestCase {
    // =========================================================================
    // Regex strategy (default)
    // =========================================================================

    public function testRegex_BlockedQuestion_ReturnsRedirect(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather|forecast|temperature'],
            redirectTemplate: 'I only help with business data.',
        );

        $this->assertSame('I only help with business data.', $boundaries->check('What is the weather today?'));
    }

    public function testRegex_InScopeQuestion_ReturnsNull(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather|forecast|temperature'],
            redirectTemplate: 'Redirect.',
        );

        $this->assertNull($boundaries->check('What were our sales last quarter?'));
    }

    public function testRegex_AdjacentOverridesBlock(): void {
        $boundaries = new DomainBoundaries(
            adjacentAllowed: ['market factors'],
            blockPatterns: ['forecast'],
            redirectTemplate: 'Redirect.',
        );

        // "forecast" matches a block pattern, but "market factors" is adjacent-allowed.
        $this->assertNull($boundaries->check('How do market factors affect our forecast?'));
    }

    public function testRegex_CaseInsensitiveMatch(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['WEATHER'],
            redirectTemplate: 'Redirect.',
        );

        $this->assertSame('Redirect.', $boundaries->check('what is the WeAtHeR'));
    }

    public function testRegex_MalformedPatternSkippedSafely(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['(unclosed', 'weather'],
            redirectTemplate: 'Redirect.',
        );

        // Malformed pattern must not throw; the valid one still matches.
        $this->assertSame('Redirect.', $boundaries->check('the weather is nice'));
        // A question matching only the malformed pattern is allowed through.
        $this->assertNull($boundaries->check('(unclosed text'));
    }

    public function testEmptyQuestion_ReturnsNull(): void {
        $boundaries = new DomainBoundaries(blockPatterns: ['weather']);

        $this->assertNull($boundaries->check('   '));
    }

    public function testNoBlockPatterns_AllowsEverything(): void {
        $boundaries = new DomainBoundaries();

        $this->assertNull($boundaries->check('literally anything'));
    }

    // =========================================================================
    // Keyword strategy
    // =========================================================================

    public function testKeyword_InScopeToken_Allowed(): void {
        $boundaries = new DomainBoundaries(
            inScope: ['sales', 'stock', 'forecast'],
            strategy: 'keyword',
            redirectTemplate: 'Redirect.',
        );

        $this->assertNull($boundaries->check('Show me the sales numbers'));
    }

    public function testKeyword_OutOfScope_Blocked(): void {
        $boundaries = new DomainBoundaries(
            inScope: ['sales', 'stock', 'forecast'],
            strategy: 'keyword',
            redirectTemplate: 'Redirect.',
        );

        $this->assertSame('Redirect.', $boundaries->check('Tell me about football'));
    }

    public function testKeyword_NoVocabulary_AllowsEverything(): void {
        $boundaries = new DomainBoundaries(strategy: 'keyword');

        $this->assertNull($boundaries->check('anything at all'));
    }

    // =========================================================================
    // Semantic strategy
    // =========================================================================

    public function testSemantic_BelowThreshold_Blocked(): void {
        $boundaries = new DomainBoundaries(
            inScope: ['sales'],
            strategy: 'semantic',
            threshold: 0.9,
            redirectTemplate: 'Redirect.',
        );

        // Orthogonal vectors → similarity 0 → below threshold → blocked.
        $embedder = fn (string $text): array => $text === 'sales' ? [1.0, 0.0] : [0.0, 1.0];

        $this->assertSame('Redirect.', $boundaries->check('weather', $embedder));
    }

    public function testSemantic_AboveThreshold_Allowed(): void {
        $boundaries = new DomainBoundaries(
            inScope: ['sales'],
            strategy: 'semantic',
            threshold: 0.5,
            redirectTemplate: 'Redirect.',
        );

        // Identical vectors → similarity 1 → above threshold → allowed.
        $embedder = fn (string $text): array => [1.0, 1.0];

        $this->assertNull($boundaries->check('revenue', $embedder));
    }

    public function testSemantic_NoEmbedder_FallsBackToRegex(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather'],
            strategy: 'semantic',
            redirectTemplate: 'Redirect.',
        );

        // No embedder supplied → falls back to regex strategy.
        $this->assertSame('Redirect.', $boundaries->check('the weather'));
    }

    public function testSemantic_ScoreRecordedInDecision(): void {
        $boundaries = new DomainBoundaries(
            inScope: ['sales'],
            strategy: 'semantic',
            threshold: 0.9,
        );
        $embedder = fn (string $text): array => $text === 'sales' ? [1.0, 0.0] : [0.0, 1.0];

        $decision = $boundaries->evaluate('weather', $embedder);

        $this->assertNotNull($decision->getScore());
        $this->assertSame(BoundaryDecision::REASON_BELOW_THRESHOLD, $decision->getReason());
    }

    // =========================================================================
    // Shadow (log-only) mode
    // =========================================================================

    public function testShadowMode_ReportsButDoesNotBlock(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather'],
            redirectTemplate: 'Redirect.',
            logOnly: true,
        );

        // check() never blocks in shadow mode.
        $this->assertNull($boundaries->check('the weather'));

        // But evaluate() still reports the would-be block.
        $decision = $boundaries->evaluate('the weather');
        $this->assertTrue($decision->isBlocked());
        $this->assertTrue($decision->isShadow());
        $this->assertSame(BoundaryDecision::REASON_BLOCKED, $decision->getReason());
    }

    // =========================================================================
    // evaluate() metadata
    // =========================================================================

    public function testEvaluate_BlockedDecisionMetadata(): void {
        $boundaries = new DomainBoundaries(
            blockPatterns: ['weather'],
            redirectTemplate: 'Redirect.',
        );

        $decision = $boundaries->evaluate('the weather');

        $this->assertTrue($decision->isBlocked());
        $this->assertFalse($decision->isShadow());
        $this->assertSame(BoundaryDecision::REASON_BLOCKED, $decision->getReason());
        $this->assertSame('regex', $decision->getStrategy());
        $this->assertSame('weather', $decision->getMatchedPattern());
        $this->assertSame('Redirect.', $decision->getRedirect());
    }

    public function testEvaluate_AdjacentOverrideMetadata(): void {
        $boundaries = new DomainBoundaries(
            adjacentAllowed: ['market'],
            blockPatterns: ['forecast'],
        );

        $decision = $boundaries->evaluate('market forecast');

        $this->assertFalse($decision->isBlocked());
        $this->assertSame(BoundaryDecision::REASON_ADJACENT_OVERRIDE, $decision->getReason());
        $this->assertSame('market', $decision->getMatchedPattern());
    }

    public function testDefaultRedirect_UsedWhenTemplateEmpty(): void {
        $boundaries = new DomainBoundaries(blockPatterns: ['weather']);

        $this->assertSame(DomainBoundaries::DEFAULT_REDIRECT, $boundaries->check('the weather'));
    }

    // =========================================================================
    // Serialization
    // =========================================================================

    public function testFromArray_ParsesAllFields(): void {
        $boundaries = DomainBoundaries::fromArray([
            'strategy' => 'keyword',
            'in_scope' => ['sales'],
            'adjacent_allowed' => ['market'],
            'block_patterns' => ['weather'],
            'redirect_template' => 'Redirect.',
            'threshold' => 0.7,
            'log_only' => true,
        ]);

        $this->assertSame('keyword', $boundaries->getStrategy());
        $this->assertSame(['sales'], $boundaries->getInScope());
        $this->assertSame(['market'], $boundaries->getAdjacentAllowed());
        $this->assertSame(['weather'], $boundaries->getBlockPatterns());
        $this->assertSame('Redirect.', $boundaries->getRedirectTemplate());
        $this->assertSame(0.7, $boundaries->getThreshold());
        $this->assertTrue($boundaries->isLogOnly());
    }

    public function testToArray_RoundTrip(): void {
        $original = new DomainBoundaries(
            inScope: ['sales'],
            adjacentAllowed: ['market'],
            blockPatterns: ['weather'],
            redirectTemplate: 'Redirect.',
            strategy: 'regex',
            threshold: 0.6,
            logOnly: false,
        );

        $restored = DomainBoundaries::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function testFromArray_Defaults(): void {
        $boundaries = DomainBoundaries::fromArray([]);

        $this->assertSame('regex', $boundaries->getStrategy());
        $this->assertSame([], $boundaries->getBlockPatterns());
        $this->assertFalse($boundaries->isLogOnly());
    }

    public function testInvalidStrategy_FallsBackToRegex(): void {
        $boundaries = new DomainBoundaries(strategy: 'nonsense');

        $this->assertSame('regex', $boundaries->getStrategy());
    }
}
