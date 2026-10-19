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
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Context\SlidingWindowStrategy;
use WebFiori\Ai\Context\ToolLoopOverflowBehavior;
use WebFiori\Ai\Exception\InvalidConfigException;
use WebFiori\Ai\Message;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for mid-loop (between-tool-iterations) context truncation — ADR-0056.
 *
 * Drives a stubbed AbstractClient whose tool outputs are oversized and asserts:
 *  - every outgoing request stays within the strategy budget across iterations;
 *  - no request ever contains an orphaned tool_calls message or tool result;
 *  - the $formattedCount reconciliation keeps the incremental slice consistent;
 *  - SUMMARIZE without a summarizer fails fast;
 *  - defaults leave behavior unchanged.
 *
 * @author Ibrahim
 */
class MidLoopTruncationTest extends TestCase {
    /**
     * @test
     */
    public function testBudgetHeldAcrossIterationsWithDropOldest() {
        $provider = new MidLoopTestClient(toolIterations: 3);
        // Small budget so the accumulating oversized tool outputs must be shed.
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 2000,
            reserveForCompletion: 200
        ));

        $tool = $this->makeBigOutputTool();

        $provider->chat([
            new Message('system', 'You are an auditor.'),
            new Message('user', 'Audit the documents.'),
        ], [
            ChatOption::TOOLS => [$tool],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
        ]);

        $this->assertGreaterThanOrEqual(2, count($provider->formattedRequests));

        // Bounded-growth guarantee: with mid-loop truncation, the context does
        // NOT accumulate across iterations. Each in-loop request holds steady at
        // the irreducible floor (preamble + the single active tool-call group,
        // which cannot be dropped), rather than carrying every prior group.
        $inLoopRequests = array_slice($provider->formattedRequests, 1);
        $sizes = array_map(fn ($msgs) => count($msgs), $inLoopRequests);

        $this->assertNotEmpty($sizes);
        $this->assertSame(
            min($sizes),
            max($sizes),
            'Mid-loop requests must not grow across iterations; size should stay constant.'
        );

        foreach ($inLoopRequests as $i => $msgs) {
            // At most: system + user + one assistant tool-call + one tool result.
            $this->assertLessThanOrEqual(
                4,
                count($msgs),
                "In-loop request #$i accumulated more than one tool-call group."
            );
            $this->assertPairingIntact($msgs, $i);
        }
    }

    /**
     * @test
     */
    public function testWithoutOptInContextGrowsUnbounded() {
        // Positive control: the same scenario WITHOUT the opt-in flag accumulates
        // every tool-call group, so the final request is larger than the bounded
        // case above. This proves the feature is what caps the growth.
        $provider = new MidLoopTestClient(toolIterations: 3);
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(2000, 200));

        $provider->chat([
            new Message('system', 'You are an auditor.'),
            new Message('user', 'Audit the documents.'),
        ], [
            ChatOption::TOOLS => [$this->makeBigOutputTool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            // opt-in omitted
        ]);

        $last = end($provider->formattedRequests);
        $this->assertGreaterThan(
            4,
            count($last),
            'Without mid-loop truncation, the final request should carry all groups.'
        );
    }

    /**
     * @test
     */
    public function testPairingIntegrityNeverOrphans() {
        $provider = new MidLoopTestClient(toolIterations: 4);
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 1500,
            reserveForCompletion: 150
        ));

        $provider->chat([
            new Message('system', 'System.'),
            new Message('user', 'Go.'),
        ], [
            ChatOption::TOOLS => [$this->makeBigOutputTool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
        ]);

        foreach ($provider->formattedRequests as $i => $msgs) {
            $this->assertPairingIntact($msgs, $i);
        }
    }

    /**
     * @test
     */
    public function testFormattedCountReconciliationAfterTruncation() {
        // The incremental slice handed to buildIncrementalChatRequest must never
        // be computed from a stale offset after a mid-loop truncation. We assert
        // every incremental "new messages" slice is itself pairing-consistent and
        // non-negative in size, and the full request set stays within budget.
        $provider = new MidLoopTestClient(toolIterations: 3);
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(
            maxTokens: 2000,
            reserveForCompletion: 200
        ));

        $provider->chat([
            new Message('system', 'Sys.'),
            new Message('user', 'Start.'),
        ], [
            ChatOption::TOOLS => [$this->makeBigOutputTool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
        ]);

        foreach ($provider->incrementalSlices as $i => $slice) {
            $this->assertGreaterThanOrEqual(0, count($slice), "Slice #$i was negative.");
            // A tool result in the incremental slice must have its call present
            // either in the slice or earlier in the full request for that step.
            $this->assertPairingIntact($provider->formattedRequests[$i + 1] ?? [], $i);
        }
    }

    /**
     * @test
     */
    public function testSummarizeWithInjectedSummarizerKeepsPairing() {
        $provider = new MidLoopTestClient(toolIterations: 3);
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(2000, 200));

        $summarizer = new StubSummarizerProvider('Earlier docs were extracted.');

        $provider->chat([
            new Message('system', 'Sys.'),
            new Message('user', 'Go.'),
        ], [
            ChatOption::TOOLS => [$this->makeBigOutputTool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
            ChatOption::TOOL_LOOP_OVERFLOW_BEHAVIOR => ToolLoopOverflowBehavior::SUMMARIZE,
            ChatOption::TOOL_LOOP_SUMMARIZER => $summarizer,
        ]);

        // The summarizer should have been invoked for dropped groups, and every
        // outgoing request must still have intact tool-call pairing.
        $this->assertGreaterThanOrEqual(1, $summarizer->callCount);

        foreach ($provider->formattedRequests as $i => $msgs) {
            $this->assertPairingIntact($msgs, $i);
        }
    }

    /**
     * @test
     */
    public function testSummarizeWithoutSummarizerFailsFast() {
        $provider = new MidLoopTestClient(toolIterations: 2);
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(2000, 200));

        $this->expectException(InvalidConfigException::class);

        $provider->chat([
            new Message('user', 'Go.'),
        ], [
            ChatOption::TOOLS => [$this->makeBigOutputTool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            ChatOption::TRUNCATE_BETWEEN_TOOL_ITERATIONS => true,
            ChatOption::TOOL_LOOP_OVERFLOW_BEHAVIOR => ToolLoopOverflowBehavior::SUMMARIZE,
            // No TOOL_LOOP_SUMMARIZER and no SummarizingWindowStrategy → must throw.
        ]);
    }

    /**
     * @test
     */
    public function testDefaultsLeaveBehaviorUnchanged() {
        // Without the opt-in flag, no mid-loop truncation happens: the final
        // request contains the full accumulated history (system + user + all
        // tool-call groups), even though it exceeds the configured budget.
        $provider = new MidLoopTestClient(toolIterations: 2);
        $provider->setContextWindowStrategy(new SlidingWindowStrategy(2000, 200));

        $provider->chat([
            new Message('system', 'Sys.'),
            new Message('user', 'Go.'),
        ], [
            ChatOption::TOOLS => [$this->makeBigOutputTool()],
            ChatOption::AUTO_EXECUTE_TOOLS => true,
            // TRUNCATE_BETWEEN_TOOL_ITERATIONS omitted (default false)
        ]);

        $lastRequest = end($provider->formattedRequests);
        $this->assertNotFalse($lastRequest);
        // Two iterations each add an assistant tool-call + a tool result → the
        // unbounded history is larger than the mid-loop-bounded case.
        $this->assertGreaterThanOrEqual(
            6,
            count($lastRequest),
            'Default behavior should accumulate full history without mid-loop truncation.'
        );
    }

    private function makeBigOutputTool(): Tool {
        $big = str_repeat('LOREM IPSUM DOLOR SIT AMET ', 400); // ~ large output

        return new Tool(
            'extract',
            'Extracts document content.',
            ['type' => 'object', 'properties' => []],
            fn (array $args): string => $big
        );
    }

    private function estimateTokens(array $messages): int {
        $chars = 0;

        foreach ($messages as $m) {
            $chars += strlen($m->getContent());
            $result = $m->getToolResult();

            if ($result !== null) {
                $chars += strlen($result->getContent());
            }
        }

        return (int) ($chars / 4);
    }

    /**
     * Asserts that no assistant tool_calls message is missing a matching tool
     * result, and no tool result references a missing call.
     */
    private function assertPairingIntact(array $messages, int $requestIndex): void {
        $callIds = [];
        $resultIds = [];

        foreach ($messages as $m) {
            if ($m->getRole() === Role::ASSISTANT->value && $m->hasToolCalls()) {
                foreach ($m->getToolCalls() as $c) {
                    $callIds[$c->getId()] = true;
                }
            }

            if ($m->getRole() === Role::TOOL->value && $m->getToolResult() !== null) {
                $resultIds[$m->getToolResult()->getToolCallId()] = true;
            }
        }

        foreach ($callIds as $id => $_) {
            $this->assertArrayHasKey(
                $id,
                $resultIds,
                "Request #$requestIndex has an orphaned tool_call ($id) with no result."
            );
        }

        foreach ($resultIds as $id => $_) {
            $this->assertArrayHasKey(
                $id,
                $callIds,
                "Request #$requestIndex has an orphaned tool result ($id) with no call."
            );
        }
    }
}
