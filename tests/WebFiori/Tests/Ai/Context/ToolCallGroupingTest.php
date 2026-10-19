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
use WebFiori\Ai\Context\ToolCallGrouping;
use WebFiori\Ai\Message;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for the ToolCallGrouping helper (ADR-0056).
 *
 * @author Ibrahim
 */
class ToolCallGroupingTest extends TestCase {
    /**
     * @test
     */
    public function testPlainMessagesAreSingletons() {
        $messages = [
            new Message(Role::SYSTEM, 'sys'),
            new Message(Role::USER, 'hi'),
            new Message(Role::ASSISTANT, 'hello'),
        ];

        $units = ToolCallGrouping::group($messages);

        $this->assertCount(3, $units);

        foreach ($units as $unit) {
            $this->assertCount(1, $unit);
        }
    }

    /**
     * @test
     */
    public function testToolCallAndResultsFormOneGroup() {
        $call1 = new ToolCall('c1', 'extract', []);
        $call2 = new ToolCall('c2', 'extract', []);

        $messages = [
            new Message(Role::USER, 'go'),
            Message::assistant('', [$call1, $call2]),
            new Message(Role::TOOL, '', [], new ToolResult('c1', 'r1', 'extract')),
            new Message(Role::TOOL, '', [], new ToolResult('c2', 'r2', 'extract')),
        ];

        $units = ToolCallGrouping::group($messages);

        // user singleton + one group holding assistant + both results.
        $this->assertCount(2, $units);
        $this->assertCount(1, $units[0]);
        $this->assertCount(3, $units[1]);
    }

    /**
     * @test
     */
    public function testFlattenIsInverseOfGroup() {
        $call = new ToolCall('c1', 'extract', []);
        $messages = [
            new Message(Role::SYSTEM, 'sys'),
            new Message(Role::USER, 'go'),
            Message::assistant('', [$call]),
            new Message(Role::TOOL, '', [], new ToolResult('c1', 'r1', 'extract')),
            new Message(Role::ASSISTANT, 'done'),
        ];

        $flattened = ToolCallGrouping::flatten(ToolCallGrouping::group($messages));

        $this->assertCount(count($messages), $flattened);

        foreach ($messages as $i => $original) {
            $this->assertSame($original, $flattened[$i], "Message #$i order changed.");
        }
    }

    /**
     * @test
     */
    public function testOrphanToolResultBecomesSingleton() {
        $messages = [
            new Message(Role::USER, 'go'),
            new Message(Role::TOOL, '', [], new ToolResult('missing', 'r', 'extract')),
        ];

        $units = ToolCallGrouping::group($messages);

        $this->assertCount(2, $units);
        $this->assertCount(1, $units[1]);
    }
}
