<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\CallbackStatusEmitter;
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\SSEStatusEmitter;

/**
 * Tests for the status emitters and ChatOption constants.
 */
class StatusEmitterTest extends TestCase {
    // =========================================================================
    // CallbackStatusEmitter
    // =========================================================================

    public function testCallbackStatusEmitter_InvokesCallback(): void {
        $received = [];
        $emitter = new CallbackStatusEmitter(function (string $status, array $context) use (&$received): void {
            $received[] = [$status, $context];
        });

        $emitter->emit('tool_call', ['name' => 'search']);
        $emitter->emit('done');

        $this->assertCount(2, $received);
        $this->assertSame('tool_call', $received[0][0]);
        $this->assertSame(['name' => 'search'], $received[0][1]);
        $this->assertSame('done', $received[1][0]);
        $this->assertSame([], $received[1][1]);
    }

    // =========================================================================
    // SSEStatusEmitter
    // =========================================================================

    public function testSSEStatusEmitter_WritesSseFormattedEvent(): void {
        $emitter = new SSEStatusEmitter();

        // emit() calls ob_flush()+flush(), which pushes the inner buffer into
        // the outer one. Nest two buffers and capture the outer.
        ob_start();
        ob_start();
        $emitter->emit('thinking', ['step' => 1]);
        ob_end_flush(); // flush inner -> outer
        $output = ob_get_clean();

        $this->assertStringContainsString("event: status\n", $output);
        $this->assertStringContainsString('data: ', $output);
        $this->assertStringContainsString('"status":"thinking"', $output);
        $this->assertStringContainsString('"step":1', $output);
        $this->assertStringEndsWith("\n\n", $output);
    }

    public function testSSEStatusEmitter_EmptyContext(): void {
        $emitter = new SSEStatusEmitter();

        ob_start();
        ob_start();
        $emitter->emit('start');
        ob_end_flush(); // flush inner -> outer
        $output = ob_get_clean();

        $this->assertStringContainsString('"status":"start"', $output);
    }

    // =========================================================================
    // ChatOption
    // =========================================================================

    public function testChatOption_ConstantsHaveExpectedValues(): void {
        $this->assertSame('model', ChatOption::MODEL);
        $this->assertSame('temperature', ChatOption::TEMPERATURE);
        $this->assertSame('max_tokens', ChatOption::MAX_TOKENS);
        $this->assertSame('json_mode', ChatOption::JSON_MODE);
        $this->assertSame('auto_execute_tools', ChatOption::AUTO_EXECUTE_TOOLS);
        $this->assertSame('tools', ChatOption::TOOLS);
        $this->assertSame('request_id', ChatOption::REQUEST_ID);
    }
}
