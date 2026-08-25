<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Temperature;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Message;
use WebFiori\Ai\Temperature\ChatContext;
use WebFiori\Ai\Temperature\TaskBasedTemperatureStrategy;

/**
 * Tests for TaskBasedTemperatureStrategy.
 */
class TaskBasedTemperatureStrategyTest extends TestCase {
    // =========================================================================
    // Default buckets — keyword category matching
    // =========================================================================

    public function testCodeKeywordsReturnZeroPointTwo(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $keywords = ['code', 'implement', 'function', 'class', 'method', 'refactor',
            'debug', 'fix', 'sql', 'query', 'regex', 'parse'];

        foreach ($keywords as $keyword) {
            $context = new ChatContext([new Message('user', "Please $keyword this for me")]);
            $this->assertSame(
                0.2,
                $strategy->temperature($context),
                "Expected 0.2 for keyword '$keyword'"
            );
        }
    }

    public function testFactualKeywordsReturnZeroPointThree(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $keywords = ['lookup', 'define', 'what is', 'when was', 'who is',
            'how many', 'list', 'calculate', 'convert', 'translate', 'fact'];

        foreach ($keywords as $keyword) {
            $context = new ChatContext([new Message('user', "$keyword something")]);
            $this->assertSame(
                0.3,
                $strategy->temperature($context),
                "Expected 0.3 for keyword '$keyword'"
            );
        }
    }

    public function testInstructionalKeywordsReturnZeroPointFive(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $keywords = ['how to', 'steps', 'guide', 'tutorial', 'instructions',
            'configure', 'setup', 'install', 'migrate', 'procedure', 'workflow'];

        foreach ($keywords as $keyword) {
            $context = new ChatContext([new Message('user', "$keyword something")]);
            $this->assertSame(
                0.5,
                $strategy->temperature($context),
                "Expected 0.5 for keyword '$keyword'"
            );
        }
    }

    public function testAnalyticalKeywordsReturnZeroPointSeven(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $keywords = ['analyze', 'compare', 'explain', 'summarize', 'describe',
            'evaluate', 'review', 'assess', 'interpret', 'discuss', 'recommend'];

        foreach ($keywords as $keyword) {
            $context = new ChatContext([new Message('user', "Please $keyword this topic")]);
            $this->assertSame(
                0.7,
                $strategy->temperature($context),
                "Expected 0.7 for keyword '$keyword'"
            );
        }
    }

    public function testCreativeKeywordsReturnZeroPointNine(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $keywords = ['write', 'generate', 'create', 'draft', 'story', 'poem',
            'essay', 'brainstorm', 'imagine', 'invent', 'compose', 'creative'];

        foreach ($keywords as $keyword) {
            $context = new ChatContext([new Message('user', "Please $keyword a masterpiece")]);
            $this->assertSame(
                0.9,
                $strategy->temperature($context),
                "Expected 0.9 for keyword '$keyword'"
            );
        }
    }

    // =========================================================================
    // Default temperature when no match
    // =========================================================================

    public function testNoMatchReturnsDefault(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([new Message('user', 'hello there')]);
        $this->assertSame(0.7, $strategy->temperature($context));
    }

    public function testEmptyMessagesReturnsDefault(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([]);
        $this->assertSame(0.7, $strategy->temperature($context));
    }

    public function testNoUserMessageReturnsDefault(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([
            new Message('system', 'You are helpful.'),
            new Message('assistant', 'How can I help?'),
        ]);
        $this->assertSame(0.7, $strategy->temperature($context));
    }

    // =========================================================================
    // Case-insensitive matching
    // =========================================================================

    public function testCaseInsensitiveMatching(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context1 = new ChatContext([new Message('user', 'CODE this function')]);
        $context2 = new ChatContext([new Message('user', 'Code this function')]);
        $context3 = new ChatContext([new Message('user', 'code this function')]);

        $this->assertSame(0.2, $strategy->temperature($context1));
        $this->assertSame(0.2, $strategy->temperature($context2));
        $this->assertSame(0.2, $strategy->temperature($context3));
    }

    public function testCaseInsensitiveMultiWordKeyword(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([new Message('user', 'WHAT IS the meaning of life?')]);
        $this->assertSame(0.3, $strategy->temperature($context));
    }

    // =========================================================================
    // Structural caps
    // =========================================================================

    public function testJsonModeCapsTemperature(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Creative keyword would normally return 0.9, but json_mode caps at 0.3
        $context = new ChatContext(
            [new Message('user', 'Write a story')],
            ['json_mode' => true]
        );

        $this->assertSame(0.3, $strategy->temperature($context));
    }

    public function testJsonSchemaCapsTemperature(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Creative keyword would normally return 0.9, but json_schema caps at 0.3
        $context = new ChatContext(
            [new Message('user', 'Generate a creative response')],
            ['json_schema' => ['type' => 'object']]
        );

        $this->assertSame(0.3, $strategy->temperature($context));
    }

    public function testJsonModeCapEvenIfKeywordSuggestsHigher(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Instructional (0.5) capped to 0.3
        $context = new ChatContext(
            [new Message('user', 'How to do this?')],
            ['json_mode' => true]
        );

        $this->assertSame(0.3, $strategy->temperature($context));
    }

    public function testToolsCapCreativeAtZeroPointSeven(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Creative keyword (0.9) capped to 0.7 by tools
        $context = new ChatContext(
            [new Message('user', 'Write a poem about PHP')],
            ['tools' => [['name' => 'search']]]
        );

        $this->assertSame(0.7, $strategy->temperature($context));
    }

    public function testToolsCapDoesNotReduceIfAlreadyBelowCap(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Code keyword (0.2) is already below 0.7, so tools cap doesn't reduce it
        $context = new ChatContext(
            [new Message('user', 'Implement a function')],
            ['tools' => [['name' => 'code_search']]]
        );

        $this->assertSame(0.2, $strategy->temperature($context));
    }

    public function testToolsCapDoesNotReduceAnalytical(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Analytical keyword (0.7) equals the cap, so no reduction
        $context = new ChatContext(
            [new Message('user', 'Analyze this data')],
            ['tools' => [['name' => 'data_tool']]]
        );

        $this->assertSame(0.7, $strategy->temperature($context));
    }

    public function testToolsCapDoesNotReduceFactual(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Factual keyword (0.3) is below the 0.7 tools cap
        $context = new ChatContext(
            [new Message('user', 'What is the capital of France?')],
            ['tools' => [['name' => 'lookup_tool']]]
        );

        $this->assertSame(0.3, $strategy->temperature($context));
    }

    // =========================================================================
    // Custom buckets
    // =========================================================================

    public function testCustomBucketsOverrideDefaults(): void {
        $customBuckets = [
            ['temperature' => 0.1, 'keywords' => ['precise']],
            ['temperature' => 1.5, 'keywords' => ['wild']],
        ];

        $strategy = new TaskBasedTemperatureStrategy($customBuckets);

        $context1 = new ChatContext([new Message('user', 'Be precise about this')]);
        $context2 = new ChatContext([new Message('user', 'Go wild with ideas')]);

        $this->assertSame(0.1, $strategy->temperature($context1));
        $this->assertSame(1.5, $strategy->temperature($context2));
    }

    public function testCustomBucketsDoNotContainDefaults(): void {
        $customBuckets = [
            ['temperature' => 0.1, 'keywords' => ['precise']],
        ];

        $strategy = new TaskBasedTemperatureStrategy($customBuckets);

        // Default 'code' keyword should not match with custom buckets
        $context = new ChatContext([new Message('user', 'code something')]);
        $this->assertSame(0.7, $strategy->temperature($context)); // returns default
    }

    // =========================================================================
    // Custom default value
    // =========================================================================

    public function testCustomDefaultValue(): void {
        $strategy = new TaskBasedTemperatureStrategy([], 0.5);

        $context = new ChatContext([new Message('user', 'hello there')]);
        $this->assertSame(0.5, $strategy->temperature($context));
    }

    public function testCustomDefaultZero(): void {
        $strategy = new TaskBasedTemperatureStrategy([], 0.0);

        $context = new ChatContext([new Message('user', 'hello there')]);
        $this->assertSame(0.0, $strategy->temperature($context));
    }

    public function testCustomDefaultTwo(): void {
        $strategy = new TaskBasedTemperatureStrategy([], 2.0);

        $context = new ChatContext([new Message('user', 'no keyword match here')]);
        $this->assertSame(2.0, $strategy->temperature($context));
    }

    // =========================================================================
    // Invalid bucket temperature
    // =========================================================================

    public function testInvalidBucketTemperatureThrowsException(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bucket temperature must be between 0.0 and 2.0');

        new TaskBasedTemperatureStrategy([
            ['temperature' => 2.5, 'keywords' => ['bad']],
        ]);
    }

    public function testNegativeBucketTemperatureThrowsException(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bucket temperature must be between 0.0 and 2.0');

        new TaskBasedTemperatureStrategy([
            ['temperature' => -0.1, 'keywords' => ['bad']],
        ]);
    }

    // =========================================================================
    // Invalid default temperature
    // =========================================================================

    public function testInvalidDefaultThrowsExceptionAboveTwo(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default temperature must be between 0.0 and 2.0');

        new TaskBasedTemperatureStrategy([], 2.5);
    }

    public function testInvalidDefaultThrowsExceptionNegative(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default temperature must be between 0.0 and 2.0');

        new TaskBasedTemperatureStrategy([], -1.0);
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function testGetBucketsReturnsConfiguredBuckets(): void {
        $customBuckets = [
            ['temperature' => 0.1, 'keywords' => ['precise']],
            ['temperature' => 1.0, 'keywords' => ['moderate']],
        ];

        $strategy = new TaskBasedTemperatureStrategy($customBuckets);
        $this->assertSame($customBuckets, $strategy->getBuckets());
    }

    public function testGetBucketsReturnsDefaultBucketsWhenNoneProvided(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $buckets = $strategy->getBuckets();
        $this->assertCount(5, $buckets);
        $this->assertSame(0.2, $buckets[0]['temperature']);
        $this->assertSame(0.3, $buckets[1]['temperature']);
        $this->assertSame(0.5, $buckets[2]['temperature']);
        $this->assertSame(0.7, $buckets[3]['temperature']);
        $this->assertSame(0.9, $buckets[4]['temperature']);
    }

    public function testGetDefaultReturnsConfiguredDefault(): void {
        $strategy = new TaskBasedTemperatureStrategy([], 1.2);
        $this->assertSame(1.2, $strategy->getDefault());
    }

    public function testGetDefaultReturnsZeroPointSevenByDefault(): void {
        $strategy = new TaskBasedTemperatureStrategy();
        $this->assertSame(0.7, $strategy->getDefault());
    }

    // =========================================================================
    // Last user message is used (not first)
    // =========================================================================

    public function testLastUserMessageIsUsed(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // First user message has 'code' (0.2), last has 'write' (0.9)
        $context = new ChatContext([
            new Message('user', 'code something'),
            new Message('assistant', 'Here is the code.'),
            new Message('user', 'write a poem about it'),
        ]);

        $this->assertSame(0.9, $strategy->temperature($context));
    }

    public function testLastUserMessageUsedNotIntermediate(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // Multiple user messages, last one has factual keyword
        $context = new ChatContext([
            new Message('user', 'Write me a story'),
            new Message('assistant', 'Once upon a time...'),
            new Message('user', 'Brainstorm more ideas'),
            new Message('assistant', 'Here are some ideas...'),
            new Message('user', 'What is the capital of France?'),
        ]);

        $this->assertSame(0.3, $strategy->temperature($context));
    }

    // =========================================================================
    // Multi-word keywords
    // =========================================================================

    public function testMultiWordKeywordWhatIs(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([new Message('user', 'what is quantum computing?')]);
        $this->assertSame(0.3, $strategy->temperature($context));
    }

    public function testMultiWordKeywordHowTo(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([new Message('user', 'how to bake a cake?')]);
        $this->assertSame(0.5, $strategy->temperature($context));
    }

    public function testMultiWordKeywordProsAndCons(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([new Message('user', 'What are the pros and cons of PHP?')]);
        $this->assertSame(0.7, $strategy->temperature($context));
    }

    public function testMultiWordKeywordFormatJson(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $context = new ChatContext([new Message('user', 'format json for this data')]);
        $this->assertSame(0.2, $strategy->temperature($context));
    }

    // =========================================================================
    // Priority: lower-temperature bucket matches first
    // =========================================================================

    public function testLowerTemperatureBucketHasPriority(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        // 'create' is creative (0.9) but 'code' is code (0.2)
        // Since buckets are sorted ascending, code (0.2) should match first
        $context = new ChatContext([new Message('user', 'create the code for login')]);
        $this->assertSame(0.2, $strategy->temperature($context));
    }

    // =========================================================================
    // Interface implementation
    // =========================================================================

    public function testImplementsTemperatureStrategyInterface(): void {
        $strategy = new TaskBasedTemperatureStrategy();

        $this->assertInstanceOf(
            \WebFiori\Ai\Temperature\TemperatureStrategyInterface::class,
            $strategy
        );
    }
}
