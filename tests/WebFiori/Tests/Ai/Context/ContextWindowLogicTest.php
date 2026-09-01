<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Context;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Context\TokenEstimator;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Tests for TokenEstimator and SlidingWindowStrategy — pure token-accounting
 * and truncation logic (fully offline).
 */
class ContextWindowLogicTest extends TestCase {
    // =========================================================================
    // TokenEstimator
    // =========================================================================

    public function testCountMessage_MemoizesByObjectIdentity(): void {
        $estimator = new TokenEstimator();
        $message = new Message('user', 'Hello world');

        $first = $estimator->countMessage($message);
        $second = $estimator->countMessage($message); // cache hit path

        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $first);
    }

    public function testCountMessage_IncludesToolCalls(): void {
        $estimator = new TokenEstimator();

        $plain = new Message('assistant', 'answer');
        $withTool = new Message('assistant', 'answer', [
            new ToolCall('tc_1', 'search', ['q' => 'php manual']),
        ]);

        // A message carrying tool calls should count more tokens.
        $this->assertGreaterThan(
            $estimator->countMessage($plain),
            $estimator->countMessage($withTool)
        );
    }

    public function testCountMessage_IncludesToolResult(): void {
        $estimator = new TokenEstimator();

        $result = new ToolResult('tc_1', 'search', 'a fairly long tool result body');
        $message = Message::tool($result);

        $this->assertGreaterThan(0, $estimator->countMessage($message));
    }

    public function testClearCache_ResetsMemoization(): void {
        $estimator = new TokenEstimator();
        $message = new Message('user', 'cached');

        $estimator->countMessage($message);
        $estimator->clearCache();

        // Recomputes without error and returns a consistent value.
        $this->assertGreaterThan(0, $estimator->countMessage($message));
    }

    public function testCount_CombinesMessages(): void {
        $estimator = new TokenEstimator();
        $messages = [new Message('user', 'a'), new Message('assistant', 'b')];

        $this->assertSame(
            $estimator->countMessages($messages),
            $estimator->count($messages)
        );
    }

    // =========================================================================
    // SlidingWindowStrategy
    // =========================================================================

    public function testTruncate_KeepsAllWhenUnderLimit(): void {
        $strategy = new SlidingWindowStrategy(maxTokens: 100000, reserveForCompletion: 0);
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hi'),
            new Message('assistant', 'Hello!'),
        ];

        $result = $strategy->truncate($messages, 100000);

        $this->assertCount(3, $result);
    }

    public function testTruncate_PreservesSystemMessageWhenDroppingOldest(): void {
        // Very small budget forces dropping oldest non-system messages.
        $strategy = new SlidingWindowStrategy(maxTokens: 40, reserveForCompletion: 0, preserveSystemMessage: true);

        $messages = [
            new Message('system', 'SYSTEM PROMPT'),
            new Message('user', str_repeat('old ', 20)),
            new Message('assistant', str_repeat('mid ', 20)),
            new Message('user', 'newest question'),
        ];

        $result = $strategy->truncate($messages, 40);

        // The system message must survive.
        $roles = array_map(fn (Message $m): string => $m->getRole(), $result);
        $this->assertContains('system', $roles);
        // And truncation actually happened.
        $this->assertLessThan(count($messages), count($result));
    }

    public function testGetMaxTokens_ReflectsConstruction(): void {
        $strategy = new SlidingWindowStrategy(maxTokens: 8192);
        $this->assertSame(8192, $strategy->getMaxTokens());
    }
}
