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
use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\AgentMessageStrategy;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\Tool;

/**
 * Tests for AgentTool.
 */
class AgentToolTest extends TestCase {
    // =========================================================================
    // Basic getters
    // =========================================================================

    public function testGetName(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('researcher', 'Researches topics', $provider, 'You are a researcher.');

        $this->assertSame('researcher', $tool->getName());
    }

    public function testGetDescription(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('writer', 'Writes articles', $provider, 'You are a writer.');

        $this->assertSame('Writes articles', $tool->getDescription());
    }

    public function testGetParameters(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('agent', 'Does stuff', $provider, 'Identity.');

        $params = $tool->getParameters();

        $this->assertSame('object', $params['type']);
        $this->assertArrayHasKey('task', $params['properties']);
        $this->assertSame('string', $params['properties']['task']['type']);
        $this->assertSame(['task'], $params['required']);
    }

    // =========================================================================
    // Profile handling
    // =========================================================================

    public function testGetProfile_FromAgentProfile(): void {
        $provider = new CapturingMockProvider();
        $profile = new AgentProfile(
            identity: 'You are a specialist.',
            skills: ['analysis'],
        );

        $tool = new AgentTool('specialist', 'Specializes', $provider, $profile);

        $this->assertSame($profile, $tool->getProfile());
        $this->assertSame('You are a specialist.', $tool->getProfile()->getIdentity());
    }

    public function testGetProfile_FromString(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('helper', 'Helps', $provider, 'You are a helpful assistant.');

        $profile = $tool->getProfile();

        $this->assertInstanceOf(AgentProfile::class, $profile);
        $this->assertSame('You are a helpful assistant.', $profile->getIdentity());
        $this->assertSame([], $profile->getSkills());
    }

    // =========================================================================
    // Message strategy
    // =========================================================================

    public function testGetMessageStrategy_Default(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.');

        $this->assertSame(AgentMessageStrategy::TASK_ONLY, $tool->getMessageStrategy());
    }

    public function testGetMessageStrategy_FullHistory(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool(
            'agent',
            'Desc',
            $provider,
            'Identity.',
            AgentMessageStrategy::FULL_HISTORY
        );

        $this->assertSame(AgentMessageStrategy::FULL_HISTORY, $tool->getMessageStrategy());
    }

    // =========================================================================
    // Provider and options
    // =========================================================================

    public function testGetProvider(): void {
        $provider = new CapturingMockProvider('test-provider');
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.');

        $this->assertSame($provider, $tool->getProvider());
    }

    public function testGetOptions(): void {
        $provider = new CapturingMockProvider();
        $options = ['temperature' => 0.5, 'max_tokens' => 100];
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.', options: $options);

        $this->assertSame($options, $tool->getOptions());
    }

    // =========================================================================
    // execute() — TASK_ONLY mode
    // =========================================================================

    public function testExecute_TaskOnly(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('agent', 'Desc', $provider, 'You are a helper.');

        $tool->execute(['task' => 'Summarize this document.']);

        // Should have exactly 2 messages: system + user
        $this->assertCount(2, $provider->lastMessages);
        $this->assertSame('system', $provider->lastMessages[0]->getRole());
        $this->assertSame('You are a helper.', $provider->lastMessages[0]->getContent());
        $this->assertSame('user', $provider->lastMessages[1]->getRole());
        $this->assertSame('Summarize this document.', $provider->lastMessages[1]->getContent());
    }

    // =========================================================================
    // execute() — FULL_HISTORY mode
    // =========================================================================

    public function testExecute_FullHistory(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool(
            'agent',
            'Desc',
            $provider,
            'You are a helper.',
            AgentMessageStrategy::FULL_HISTORY
        );

        $context = [
            new Message('user', 'First message.'),
            new Message('assistant', 'First reply.'),
            new Message('user', 'Second message.'),
        ];

        $tool->setConversationContext($context);
        $tool->execute(['task' => 'Continue the conversation.']);

        // Should have: system + 3 context + user = 5 messages
        $this->assertCount(5, $provider->lastMessages);
        $this->assertSame('system', $provider->lastMessages[0]->getRole());
        $this->assertSame('user', $provider->lastMessages[1]->getRole());
        $this->assertSame('First message.', $provider->lastMessages[1]->getContent());
        $this->assertSame('assistant', $provider->lastMessages[2]->getRole());
        $this->assertSame('First reply.', $provider->lastMessages[2]->getContent());
        $this->assertSame('user', $provider->lastMessages[3]->getRole());
        $this->assertSame('Second message.', $provider->lastMessages[3]->getContent());
        $this->assertSame('user', $provider->lastMessages[4]->getRole());
        $this->assertSame('Continue the conversation.', $provider->lastMessages[4]->getContent());
    }

    // =========================================================================
    // execute() — tools from profile
    // =========================================================================

    public function testExecute_WithProfileTools(): void {
        $provider = new CapturingMockProvider();
        $profileTool = new Tool('calculator', 'Calculates', ['type' => 'object'], fn () => '42');

        $profile = new AgentProfile(
            identity: 'Math agent.',
            tools: [$profileTool],
        );

        $tool = new AgentTool('math_agent', 'Does math', $provider, $profile);
        $tool->execute(['task' => 'Calculate 6*7.']);

        // Options should include tools and auto_execute_tools
        $this->assertArrayHasKey(ChatOption::TOOLS, $provider->lastOptions);
        $this->assertCount(1, $provider->lastOptions[ChatOption::TOOLS]);
        $this->assertSame($profileTool, $provider->lastOptions[ChatOption::TOOLS][0]);
        $this->assertTrue($provider->lastOptions[ChatOption::AUTO_EXECUTE_TOOLS]);
    }

    // =========================================================================
    // execute() — custom options
    // =========================================================================

    public function testExecute_WithCustomOptions(): void {
        $provider = new CapturingMockProvider();
        $options = [ChatOption::TEMPERATURE => 0.2, ChatOption::MAX_TOKENS => 500];
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.', options: $options);

        $tool->execute(['task' => 'Do something.']);

        $this->assertSame(0.2, $provider->lastOptions[ChatOption::TEMPERATURE]);
        $this->assertSame(500, $provider->lastOptions[ChatOption::MAX_TOKENS]);
    }

    // =========================================================================
    // execute() — return value
    // =========================================================================

    public function testExecute_ReturnsProviderContent(): void {
        $provider = new CapturingMockProvider('mock', 'The answer is 42.');
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.');

        $result = $tool->execute(['task' => 'What is the answer?']);

        $this->assertSame('The answer is 42.', $result);
    }

    // =========================================================================
    // Conversation context
    // =========================================================================

    public function testSetConversationContext(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.');

        $messages = [
            new Message('user', 'Hello'),
            new Message('assistant', 'Hi there'),
        ];

        $tool->setConversationContext($messages);

        $this->assertCount(2, $tool->getConversationContext());
    }

    public function testGetConversationContext(): void {
        $provider = new CapturingMockProvider();
        $tool = new AgentTool('agent', 'Desc', $provider, 'Identity.');

        // Default should be empty
        $this->assertSame([], $tool->getConversationContext());

        $messages = [new Message('user', 'Test')];
        $tool->setConversationContext($messages);

        $this->assertCount(1, $tool->getConversationContext());
        $this->assertSame('Test', $tool->getConversationContext()[0]->getContent());
    }

    // =========================================================================
    // System prompt from profile render()
    // =========================================================================

    public function testExecute_SystemPromptFromProfile(): void {
        $provider = new CapturingMockProvider();
        $profile = new AgentProfile(
            identity: 'You are an expert.',
            skills: ['PHP', 'Testing'],
            instructions: ['Be thorough'],
        );

        $tool = new AgentTool('expert', 'Expert agent', $provider, $profile);
        $tool->execute(['task' => 'Review code.']);

        $systemMessage = $provider->lastMessages[0];
        $expectedContent = $profile->render();

        $this->assertSame('system', $systemMessage->getRole());
        $this->assertSame($expectedContent, $systemMessage->getContent());
        $this->assertStringContainsString('You are an expert.', $systemMessage->getContent());
        $this->assertStringContainsString('## Skills', $systemMessage->getContent());
        $this->assertStringContainsString('- PHP', $systemMessage->getContent());
        $this->assertStringContainsString('## Instructions', $systemMessage->getContent());
        $this->assertStringContainsString('- Be thorough', $systemMessage->getContent());
    }
}
