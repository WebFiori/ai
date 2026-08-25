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
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\ContentPart;
use WebFiori\Ai\Message;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\ToolCall;
use WebFiori\Ai\Tool\ToolResult;

/**
 * Unit tests for the Role enum, ChatOption constants, and Message factory methods.
 *
 * @author Ibrahim
 */
class RoleAndChatOptionTest extends TestCase {
    // ─── Role Enum ────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function testRoleEnumValues() {
        $this->assertEquals('user', Role::USER->value);
        $this->assertEquals('system', Role::SYSTEM->value);
        $this->assertEquals('assistant', Role::ASSISTANT->value);
        $this->assertEquals('tool', Role::TOOL->value);
    }

    /**
     * @test
     */
    public function testRoleEnumCaseCount() {
        $this->assertCount(4, Role::cases());
    }

    /**
     * @test
     */
    public function testRoleEnumFromString() {
        $this->assertEquals(Role::USER, Role::from('user'));
        $this->assertEquals(Role::SYSTEM, Role::from('system'));
        $this->assertEquals(Role::ASSISTANT, Role::from('assistant'));
        $this->assertEquals(Role::TOOL, Role::from('tool'));
    }

    /**
     * @test
     */
    public function testRoleEnumTryFromInvalid() {
        $this->assertNull(Role::tryFrom('invalid'));
        $this->assertNull(Role::tryFrom(''));
        $this->assertNull(Role::tryFrom('User'));
    }

    // ─── ChatOption Constants ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function testChatOptionCoreConstants() {
        $this->assertEquals('model', ChatOption::MODEL);
        $this->assertEquals('temperature', ChatOption::TEMPERATURE);
        $this->assertEquals('max_tokens', ChatOption::MAX_TOKENS);
        $this->assertEquals('top_p', ChatOption::TOP_P);
        $this->assertEquals('stop', ChatOption::STOP);
    }

    /**
     * @test
     */
    public function testChatOptionToolConstants() {
        $this->assertEquals('tools', ChatOption::TOOLS);
        $this->assertEquals('auto_execute_tools', ChatOption::AUTO_EXECUTE_TOOLS);
        $this->assertEquals('max_tool_iterations', ChatOption::MAX_TOOL_ITERATIONS);
        $this->assertEquals('parallel_tool_execution', ChatOption::PARALLEL_TOOL_EXECUTION);
    }

    /**
     * @test
     */
    public function testChatOptionStructuredOutputConstants() {
        $this->assertEquals('json_mode', ChatOption::JSON_MODE);
        $this->assertEquals('json_schema', ChatOption::JSON_SCHEMA);
    }

    /**
     * @test
     */
    public function testChatOptionRoutingConstants() {
        $this->assertEquals('force_provider', ChatOption::FORCE_PROVIDER);
    }

    /**
     * @test
     */
    public function testChatOptionMetadataConstants() {
        $this->assertEquals('request_id', ChatOption::REQUEST_ID);
    }

    /**
     * @test
     */
    public function testChatOptionEmbeddingConstants() {
        $this->assertEquals('dimensions', ChatOption::DIMENSIONS);
        $this->assertEquals('embedding_model', ChatOption::EMBEDDING_MODEL);
    }

    /**
     * @test
     */
    public function testChatOptionGoogleInteractionsConstants() {
        $this->assertEquals('previous_interaction_id', ChatOption::PREVIOUS_INTERACTION_ID);
    }

    /**
     * @test
     */
    public function testChatOptionCannotBeInstantiated() {
        $reflection = new \ReflectionClass(ChatOption::class);
        $constructor = $reflection->getConstructor();
        $this->assertTrue($constructor->isPrivate());
    }

    /**
     * @test
     */
    public function testChatOptionUsedAsArrayKey() {
        $options = [
            ChatOption::TEMPERATURE => 0.7,
            ChatOption::MAX_TOKENS => 1024,
            ChatOption::JSON_MODE => true,
            ChatOption::TOOLS => [],
        ];

        $this->assertEquals(0.7, $options[ChatOption::TEMPERATURE]);
        $this->assertEquals(1024, $options[ChatOption::MAX_TOKENS]);
        $this->assertTrue($options[ChatOption::JSON_MODE]);
        $this->assertEmpty($options[ChatOption::TOOLS]);
    }

    // ─── Message with Role Enum ───────────────────────────────────────────────

    /**
     * @test
     */
    public function testMessageConstructorAcceptsRoleEnum() {
        $message = new Message(Role::USER, 'Hello');
        $this->assertEquals('user', $message->getRole());
        $this->assertEquals('Hello', $message->getContent());
    }

    /**
     * @test
     */
    public function testMessageConstructorAcceptsRoleEnumAllCases() {
        $system = new Message(Role::SYSTEM, 'Be helpful');
        $user = new Message(Role::USER, 'Hi');
        $assistant = new Message(Role::ASSISTANT, 'Hello!');
        $tool = new Message(Role::TOOL, '');

        $this->assertEquals('system', $system->getRole());
        $this->assertEquals('user', $user->getRole());
        $this->assertEquals('assistant', $assistant->getRole());
        $this->assertEquals('tool', $tool->getRole());
    }

    /**
     * @test
     */
    public function testMessageConstructorStillAcceptsString() {
        $message = new Message('user', 'Hello');
        $this->assertEquals('user', $message->getRole());
        $this->assertEquals('Hello', $message->getContent());
    }

    /**
     * @test
     */
    public function testMessageWithRoleEnumAndToolCalls() {
        $toolCall = new ToolCall('call-1', 'get_weather', ['city' => 'Amman']);
        $message = new Message(Role::ASSISTANT, '', [$toolCall]);

        $this->assertEquals('assistant', $message->getRole());
        $this->assertTrue($message->hasToolCalls());
        $this->assertCount(1, $message->getToolCalls());
    }

    /**
     * @test
     */
    public function testMessageWithRoleEnumAndMultiModal() {
        $message = new Message(Role::USER, [
            ContentPart::text('Describe this image'),
            ContentPart::imageUrl('https://example.com/img.jpg'),
        ]);

        $this->assertEquals('user', $message->getRole());
        $this->assertTrue($message->isMultiModal());
        $this->assertTrue($message->hasImages());
    }

    // ─── Message Static Factories ─────────────────────────────────────────────

    /**
     * @test
     */
    public function testMessageUserFactory() {
        $message = Message::user('What is PHP?');
        $this->assertEquals('user', $message->getRole());
        $this->assertEquals('What is PHP?', $message->getContent());
        $this->assertFalse($message->isMultiModal());
    }

    /**
     * @test
     */
    public function testMessageUserFactoryWithMultiModal() {
        $message = Message::user([
            ContentPart::text('Describe this'),
            ContentPart::imageUrl('https://example.com/img.jpg'),
        ]);
        $this->assertEquals('user', $message->getRole());
        $this->assertTrue($message->isMultiModal());
        $this->assertTrue($message->hasImages());
    }

    /**
     * @test
     */
    public function testMessageSystemFactory() {
        $message = Message::system('You are a helpful assistant.');
        $this->assertEquals('system', $message->getRole());
        $this->assertEquals('You are a helpful assistant.', $message->getContent());
    }

    /**
     * @test
     */
    public function testMessageAssistantFactory() {
        $message = Message::assistant('Sure, I can help!');
        $this->assertEquals('assistant', $message->getRole());
        $this->assertEquals('Sure, I can help!', $message->getContent());
        $this->assertFalse($message->hasToolCalls());
    }

    /**
     * @test
     */
    public function testMessageAssistantFactoryWithToolCalls() {
        $toolCall = new ToolCall('call-1', 'search', ['q' => 'php']);
        $message = Message::assistant('', [$toolCall]);
        $this->assertEquals('assistant', $message->getRole());
        $this->assertTrue($message->hasToolCalls());
        $this->assertCount(1, $message->getToolCalls());
    }

    /**
     * @test
     */
    public function testMessageToolFactory() {
        $toolResult = new ToolResult('call-1', '{"temp": 22}', 'get_weather');
        $message = Message::tool($toolResult);
        $this->assertEquals('tool', $message->getRole());
        $this->assertSame($toolResult, $message->getToolResult());
        $this->assertEquals('', $message->getContent());
    }

    /**
     * @test
     */
    public function testFactoryMethodsEquivalentToConstructor() {
        $factoryUser = Message::user('Hello');
        $constructorUser = new Message(Role::USER, 'Hello');
        $stringUser = new Message('user', 'Hello');

        $this->assertEquals($factoryUser->getRole(), $constructorUser->getRole());
        $this->assertEquals($factoryUser->getContent(), $constructorUser->getContent());
        $this->assertEquals($factoryUser->getRole(), $stringUser->getRole());
        $this->assertEquals($factoryUser->getContent(), $stringUser->getContent());
    }
}
