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
use WebFiori\Ai\Message;
use WebFiori\Ai\Role;
use WebFiori\Ai\Tool\KeywordRememberStrategy;
use WebFiori\Ai\Tool\LLMRememberStrategy;
use WebFiori\Ai\Tool\ManualRememberStrategy;

/**
 * Tests for ManualRememberStrategy, KeywordRememberStrategy, and LLMRememberStrategy.
 */
class RememberStrategyTest extends TestCase {
    // =========================================================================
    // ManualRememberStrategy
    // =========================================================================

    public function testManual_AlwaysReturnsEmpty(): void {
        $strategy = new ManualRememberStrategy();

        $messages = [
            new Message(Role::USER, 'Actually, the correct answer is 42.'),
            new Message(Role::ASSISTANT, 'Noted!'),
        ];

        $result = $strategy->extract($messages, 'Some response');

        $this->assertSame([], $result);
    }

    // =========================================================================
    // KeywordRememberStrategy
    // =========================================================================

    public function testKeyword_DetectsActually(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'Actually, the database uses PostgreSQL not MySQL.')];

        $result = $strategy->extract($messages, 'OK noted.');

        $this->assertCount(1, $result);
        $this->assertSame('Actually, the database uses PostgreSQL not MySQL.', $result[0]);
    }

    public function testKeyword_DetectsCorrection(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'Correction: the API endpoint is /v2/users.')];

        $result = $strategy->extract($messages, 'Acknowledged.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Correction:', $result[0]);
    }

    public function testKeyword_DetectsWrong(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'That is wrong, the port should be 8080.')];

        $result = $strategy->extract($messages, 'Let me fix that.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('wrong', $result[0]);
    }

    public function testKeyword_DetectsRememberThat(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'Remember that I prefer tabs over spaces.')];

        $result = $strategy->extract($messages, 'Got it.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('remember that', strtolower($result[0]));
    }

    public function testKeyword_DetectsNote(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'Note: our CI uses GitHub Actions.')];

        $result = $strategy->extract($messages, 'Understood.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Note:', $result[0]);
    }

    public function testKeyword_DetectsImportant(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'Important: never use eval() in production.')];

        $result = $strategy->extract($messages, 'Absolutely.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Important:', $result[0]);
    }

    public function testKeyword_DetectsInsteadUse(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'Instead, use prepared statements for all queries.')];

        $result = $strategy->extract($messages, 'Will do.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Instead, use', $result[0]);
    }

    public function testKeyword_NoMatchReturnsEmpty(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'What is the weather like today?')];

        $result = $strategy->extract($messages, 'It is sunny.');

        $this->assertSame([], $result);
    }

    public function testKeyword_EmptyMessagesReturnsEmpty(): void {
        $strategy = new KeywordRememberStrategy();

        $result = $strategy->extract([], 'Some response');

        $this->assertSame([], $result);
    }

    public function testKeyword_OnlyUserMessagesChecked(): void {
        $strategy = new KeywordRememberStrategy();

        // System and assistant messages contain keywords but no user message does
        $messages = [
            new Message(Role::SYSTEM, 'Important: you are a helpful assistant.'),
            new Message(Role::ASSISTANT, 'Actually, let me correct that.'),
            new Message(Role::USER, 'Tell me about PHP arrays.'),
        ];

        $result = $strategy->extract($messages, 'PHP arrays are flexible.');

        // Should not match because the last user message has no keywords
        $this->assertSame([], $result);
    }

    public function testKeyword_CaseInsensitive(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [new Message(Role::USER, 'ACTUALLY, the correct port is 3306.')];

        $result = $strategy->extract($messages, 'OK.');

        $this->assertCount(1, $result);
    }

    public function testKeyword_CustomPatterns(): void {
        $strategy = new KeywordRememberStrategy(['/\bpreference\b/i', '/\bupdate me\b/i']);

        $messages = [new Message(Role::USER, 'My preference is dark mode.')];

        $result = $strategy->extract($messages, 'Noted.');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('preference', $result[0]);

        // Default patterns should NOT match with custom patterns
        $messages2 = [new Message(Role::USER, 'Actually, this should not match.')];
        $result2 = $strategy->extract($messages2, 'OK.');
        $this->assertSame([], $result2);
    }

    public function testKeyword_ReturnsFullMessageContent(): void {
        $strategy = new KeywordRememberStrategy();

        $fullMessage = 'Actually, the correct version is PHP 8.3, not 8.2. Please update all references.';
        $messages = [new Message(Role::USER, $fullMessage)];

        $result = $strategy->extract($messages, 'Updated.');

        $this->assertCount(1, $result);
        $this->assertSame($fullMessage, $result[0]);
    }

    public function testKeyword_UsesLastUserMessage(): void {
        $strategy = new KeywordRememberStrategy();

        $messages = [
            new Message(Role::USER, 'Hello, how are you?'),
            new Message(Role::ASSISTANT, 'I am fine.'),
            new Message(Role::USER, 'Actually, my name is Ibrahim.'),
        ];

        $result = $strategy->extract($messages, 'Nice to meet you, Ibrahim.');

        $this->assertCount(1, $result);
        $this->assertSame('Actually, my name is Ibrahim.', $result[0]);
    }

    // =========================================================================
    // LLMRememberStrategy
    // =========================================================================

    public function testLLM_ExtractsFactsFromResponse(): void {
        $provider = new CapturingMockProvider('classifier');
        $provider->setResponseContent('["User prefers dark mode", "Project uses PostgreSQL"]');

        $strategy = new LLMRememberStrategy($provider);

        $messages = [new Message(Role::USER, 'I prefer dark mode and we use PostgreSQL.')];

        $result = $strategy->extract($messages, 'Noted your preferences.');

        $this->assertCount(2, $result);
        $this->assertSame('User prefers dark mode', $result[0]);
        $this->assertSame('Project uses PostgreSQL', $result[1]);
    }

    public function testLLM_EmptyArrayWhenNothingToRemember(): void {
        $provider = new CapturingMockProvider('classifier');
        $provider->setResponseContent('[]');

        $strategy = new LLMRememberStrategy($provider);

        $messages = [new Message(Role::USER, 'What time is it?')];

        $result = $strategy->extract($messages, 'I cannot tell the time.');

        $this->assertSame([], $result);
    }

    public function testLLM_HandlesInvalidJsonGracefully(): void {
        $provider = new CapturingMockProvider('classifier');
        $provider->setResponseContent('This is not valid JSON at all.');

        $strategy = new LLMRememberStrategy($provider);

        $messages = [new Message(Role::USER, 'Something.')];

        $result = $strategy->extract($messages, 'Response.');

        $this->assertSame([], $result);
    }

    public function testLLM_PassesCorrectPromptToClassifier(): void {
        $provider = new CapturingMockProvider('classifier');
        $provider->setResponseContent('[]');

        $strategy = new LLMRememberStrategy($provider);

        $messages = [new Message(Role::USER, 'My name is Ibrahim.')];

        $strategy->extract($messages, 'Hello Ibrahim!');

        // Verify the messages sent to the classifier
        $this->assertCount(2, $provider->lastMessages);
        $this->assertSame('system', $provider->lastMessages[0]->getRole());
        $this->assertStringContainsString('extract facts', $provider->lastMessages[0]->getContent());

        $userPrompt = $provider->lastMessages[1]->getContent();
        $this->assertStringContainsString('My name is Ibrahim.', $userPrompt);
        $this->assertStringContainsString('Hello Ibrahim!', $userPrompt);
        $this->assertStringContainsString('User said:', $userPrompt);
        $this->assertStringContainsString('Assistant responded:', $userPrompt);
    }

    public function testLLM_UsesConfiguredModel(): void {
        $provider = new CapturingMockProvider('classifier');
        $provider->setResponseContent('[]');

        $strategy = new LLMRememberStrategy($provider, 'gpt-4o-mini');

        $messages = [new Message(Role::USER, 'Test.')];
        $strategy->extract($messages, 'Response.');

        $this->assertArrayHasKey('model', $provider->lastOptions);
        $this->assertSame('gpt-4o-mini', $provider->lastOptions['model']);
    }

    public function testLLM_GettersWork(): void {
        $provider = new CapturingMockProvider('classifier');
        $strategy = new LLMRememberStrategy($provider, 'custom-model');

        $this->assertSame($provider, $strategy->getClassifier());
        $this->assertSame('custom-model', $strategy->getModel());
    }
}
