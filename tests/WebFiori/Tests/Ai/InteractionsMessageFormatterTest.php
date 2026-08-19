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
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Google\InteractionsMessageFormatter;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Tests for #100: InteractionsMessageFormatter.
 */
class InteractionsMessageFormatterTest extends TestCase {
    private InteractionsMessageFormatter $formatter;

    protected function setUp(): void {
        $this->formatter = new InteractionsMessageFormatter();
    }

    // =========================================================================
    // System messages
    // =========================================================================

    public function testSystemMessageIsExcludedFromInput(): void {
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hi'),
        ];

        $input = $this->formatter->format($messages);

        $this->assertCount(1, $input);
        $this->assertEquals('user_input', $input[0]['type']);
    }

    public function testExtractSingleSystemInstruction(): void {
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'Hi'),
        ];

        $system = $this->formatter->extractSystemInstruction($messages);

        $this->assertEquals('You are helpful.', $system);
    }

    public function testExtractMultipleSystemInstructions(): void {
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('system', 'Respond in French.'),
            new Message('user', 'Hi'),
        ];

        $system = $this->formatter->extractSystemInstruction($messages);

        $this->assertEquals("You are helpful.\nRespond in French.", $system);
    }

    public function testExtractSystemInstructionReturnsNullWhenNone(): void {
        $messages = [new Message('user', 'Hi')];

        $this->assertNull($this->formatter->extractSystemInstruction($messages));
    }

    // =========================================================================
    // User messages
    // =========================================================================

    public function testUserTextMessage(): void {
        $messages = [new Message('user', 'Hello world')];

        $input = $this->formatter->format($messages);

        $this->assertCount(1, $input);
        $this->assertEquals('user_input', $input[0]['type']);
        $this->assertCount(1, $input[0]['content']);
        $this->assertEquals('text', $input[0]['content'][0]['type']);
        $this->assertEquals('Hello world', $input[0]['content'][0]['text']);
    }

    public function testMultipleUserMessages(): void {
        $messages = [
            new Message('user', 'First'),
            new Message('user', 'Second'),
        ];

        $input = $this->formatter->format($messages);

        $this->assertCount(2, $input);
        $this->assertEquals('First', $input[0]['content'][0]['text']);
        $this->assertEquals('Second', $input[1]['content'][0]['text']);
    }

    public function testUserMessageWithMultipleTextParts(): void {
        $messages = [
            new Message('user', [
                ContentPart::text('What is this?'),
                ContentPart::text('Explain it.'),
            ]),
        ];

        $input = $this->formatter->format($messages);

        $this->assertEquals('user_input', $input[0]['type']);
        $this->assertCount(2, $input[0]['content']);
        $this->assertEquals('What is this?', $input[0]['content'][0]['text']);
        $this->assertEquals('Explain it.', $input[0]['content'][1]['text']);
    }

    // =========================================================================
    // Assistant messages
    // =========================================================================

    public function testAssistantMessageWithRawStepsIsExpanded(): void {
        $steps = [
            ['type' => 'thought', 'text' => 'Let me think...'],
            ['type' => 'text', 'text' => 'The answer is 42.'],
        ];

        $message = new Message('assistant', '');
        $message->setRawSteps($steps);

        $input = $this->formatter->format([$message]);

        // Raw steps are expanded directly into input
        $this->assertCount(2, $input);
        $this->assertEquals('thought', $input[0]['type']);
        $this->assertEquals('text', $input[1]['type']);
    }

    public function testAssistantMessageWithoutRawStepsUsesModelTurn(): void {
        $message = new Message('assistant', 'I can help with that.');

        $input = $this->formatter->format([$message]);

        $this->assertCount(1, $input);
        $this->assertEquals('model_turn', $input[0]['type']);
        $this->assertEquals('text', $input[0]['content'][0]['type']);
        $this->assertEquals('I can help with that.', $input[0]['content'][0]['text']);
    }

    public function testEmptyAssistantMessageProducesNoInput(): void {
        $message = new Message('assistant', '');

        $input = $this->formatter->format([$message]);

        $this->assertCount(0, $input);
    }

    // =========================================================================
    // Multimodal user messages
    // =========================================================================

    public function testUserMessageWithImageBase64(): void {
        $messages = [
            new Message('user', [
                ContentPart::text('What is this?'),
                ContentPart::imageBase64('abc123', 'image/png'),
            ]),
        ];

        $input = $this->formatter->format($messages);

        $this->assertEquals('user_input', $input[0]['type']);
        $this->assertCount(2, $input[0]['content']);
        $this->assertEquals('text', $input[0]['content'][0]['type']);
        $this->assertEquals('image', $input[0]['content'][1]['type']);
        $this->assertArrayHasKey('image', $input[0]['content'][1]);
        $this->assertEquals('image/png', $input[0]['content'][1]['image']['mime_type']);
    }

    public function testUserMessageWithImageUrl(): void {
        $messages = [
            new Message('user', [
                ContentPart::imageUrl('https://example.com/photo.jpg'),
            ]),
        ];

        $input = $this->formatter->format($messages);

        $this->assertEquals('image_url', $input[0]['content'][0]['type']);
        $this->assertEquals('https://example.com/photo.jpg', $input[0]['content'][0]['image_url']);
    }

    public function testUserMessageWithDocument(): void {
        $messages = [
            new Message('user', [
                ContentPart::text('Summarize this PDF.'),
                ContentPart::document(base64_encode('pdf content'), 'application/pdf'),
            ]),
        ];

        $input = $this->formatter->format($messages);

        $this->assertCount(2, $input[0]['content']);
        $this->assertEquals('file', $input[0]['content'][1]['type']);
        $this->assertArrayHasKey('file', $input[0]['content'][1]);
        $this->assertEquals('application/pdf', $input[0]['content'][1]['file']['mime_type']);
    }

    // =========================================================================
    // ToolResult message
    // =========================================================================

    public function testToolResultMessage(): void {
        $result = new ToolResult('call_abc123', '{"temp": 72}', 'get_weather');
        $message = new Message('tool', '', [], $result);

        $input = $this->formatter->format([$message]);

        $this->assertCount(1, $input);
        $this->assertEquals('function_result', $input[0]['type']);
        $this->assertEquals('get_weather', $input[0]['name']);
        $this->assertEquals('call_abc123', $input[0]['call_id']);
        $this->assertEquals('text', $input[0]['result'][0]['type']);
        $this->assertEquals('{"temp": 72}', $input[0]['result'][0]['text']);
    }

    public function testMultipleToolResults(): void {
        $result1 = new ToolResult('call_1', 'result1', 'tool_a');
        $result2 = new ToolResult('call_2', 'result2', 'tool_b');

        $input = $this->formatter->format([
            new Message('tool', '', [], $result1),
            new Message('tool', '', [], $result2),
        ]);

        $this->assertCount(2, $input);
        $this->assertEquals('call_1', $input[0]['call_id']);
        $this->assertEquals('call_2', $input[1]['call_id']);
    }

    // =========================================================================
    // Multi-turn conversation
    // =========================================================================

    public function testFullConversationTurn(): void {
        $steps = [
            ['type' => 'function_call', 'id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['location' => 'NYC']],
        ];
        $assistantMsg = new Message('assistant', '');
        $assistantMsg->setRawSteps($steps);

        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('user', 'What is the weather in NYC?'),
            $assistantMsg,
            new Message('tool', '', [], new ToolResult('call_1', '72°F and sunny', 'get_weather')),
        ];

        $input = $this->formatter->format($messages);

        // system excluded, user + function_call step + function_result = 3
        $this->assertCount(3, $input);
        $this->assertEquals('user_input', $input[0]['type']);
        $this->assertEquals('function_call', $input[1]['type']);
        $this->assertEquals('function_result', $input[2]['type']);
    }

    // =========================================================================
    // ToolResult name
    // =========================================================================

    public function testToolResultGetName(): void {
        $result = new ToolResult('call_1', 'output', 'my_tool');
        $this->assertEquals('my_tool', $result->getName());
    }

    public function testToolResultDefaultName(): void {
        $result = new ToolResult('call_1', 'output');
        $this->assertEquals('', $result->getName());
    }

    // =========================================================================
    // Message raw steps
    // =========================================================================

    public function testMessageGetSetRawSteps(): void {
        $message = new Message('assistant', 'Hello');
        $this->assertNull($message->getRawSteps());

        $steps = [['type' => 'text', 'text' => 'Hello']];
        $message->setRawSteps($steps);

        $this->assertEquals($steps, $message->getRawSteps());
    }
}
