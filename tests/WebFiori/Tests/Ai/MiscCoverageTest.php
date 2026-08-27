<?php

namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\StatusMessageFormatter;
use WebFiori\Ai\Status;
use WebFiori\Ai\Tool\LazyTool;
use WebFiori\Ai\Tool\Tool;
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Tests for LazyTool and StatusMessageFormatter.
 */
class MiscCoverageTest extends TestCase {
    // ==================== LAZY TOOL ====================

    /**
     * @test
     */
    public function testLazyToolMetadata() {
        $tool = new LazyTool(
            'search_db',
            'Searches the database',
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            fn() => new Tool('search_db', 'Searches', [], fn($args) => 'result')
        );

        $this->assertEquals('search_db', $tool->getName());
        $this->assertEquals('Searches the database', $tool->getDescription());
        $this->assertArrayHasKey('type', $tool->getParameters());
        $this->assertFalse($tool->isInitialized());
    }

    /**
     * @test
     */
    public function testLazyToolExecuteWithToolInterface() {
        $innerTool = new Tool(
            'calc',
            'Calculator',
            ['type' => 'object', 'properties' => ['expr' => ['type' => 'string']]],
            function (array $args): string { return 'result: ' . ($args['expr'] ?? ''); }
        );

        $factoryCalled = false;
        $tool = new LazyTool(
            'calc',
            'Calculator',
            ['type' => 'object', 'properties' => ['expr' => ['type' => 'string']]],
            function () use ($innerTool, &$factoryCalled) {
                $factoryCalled = true;
                return $innerTool;
            }
        );

        $this->assertFalse($factoryCalled);
        $this->assertFalse($tool->isInitialized());

        $result = $tool->execute(['expr' => '2+2']);

        $this->assertTrue($factoryCalled);
        $this->assertTrue($tool->isInitialized());
        $this->assertEquals('result: 2+2', $result);
    }

    /**
     * @test
     */
    public function testLazyToolExecuteWithCallable() {
        $tool = new LazyTool(
            'greet',
            'Greeter',
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            fn() => function (array $args): string { return 'Hello ' . ($args['name'] ?? 'world'); }
        );

        $result = $tool->execute(['name' => 'PHP']);
        $this->assertEquals('Hello PHP', $result);
        $this->assertTrue($tool->isInitialized());
    }

    /**
     * @test
     */
    public function testLazyToolCachesInstance() {
        $callCount = 0;
        $tool = new LazyTool(
            'counter',
            'Counter tool',
            [],
            function () use (&$callCount) {
                $callCount++;
                return fn($args) => "call $callCount";
            }
        );

        $tool->execute([]);
        $tool->execute([]);
        $tool->execute([]);

        $this->assertEquals(1, $callCount);
    }

    /**
     * @test
     */
    public function testLazyToolInitialize() {
        $initialized = false;
        $tool = new LazyTool(
            'init_test',
            'Test init',
            [],
            function () use (&$initialized) {
                $initialized = true;
                return fn($args) => '';
            }
        );

        $this->assertFalse($initialized);
        $tool->initialize();
        $this->assertTrue($initialized);
        $this->assertTrue($tool->isInitialized());
    }

    /**
     * @test
     */
    public function testLazyToolInvalidFactoryThrows() {
        $tool = new LazyTool(
            'bad',
            'Bad tool',
            [],
            fn() => 'not a tool or callable'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LazyTool factory must return');
        $tool->execute([]);
    }

    // ==================== STATUS MESSAGE FORMATTER ====================

    /**
     * @test
     */
    public function testDefaultMessages() {
        $formatter = new StatusMessageFormatter();

        $this->assertEquals('Preparing your request...', $formatter->format(Status::PREPARING));
        $this->assertEquals('Thinking...', $formatter->format(Status::SENDING_REQUEST));
        $this->assertEquals('Found cached response.', $formatter->format(Status::CACHE_HIT));
    }

    /**
     * @test
     */
    public function testToolCallingPlaceholder() {
        $formatter = new StatusMessageFormatter();

        $result = $formatter->format(Status::TOOL_CALLING, ['tool' => 'get_weather']);
        $this->assertEquals('Using get_weather tool...', $result);
    }

    /**
     * @test
     */
    public function testToolCompletedPlaceholders() {
        $formatter = new StatusMessageFormatter();

        $result = $formatter->format(Status::TOOL_COMPLETED, ['tool' => 'search', 'duration_ms' => 150]);
        $this->assertEquals('Finished search in 150ms.', $result);
    }

    /**
     * @test
     */
    public function testCompletedWithDuration() {
        $formatter = new StatusMessageFormatter();

        $result = $formatter->format(Status::COMPLETED, ['duration_ms' => 3200]);
        $this->assertEquals('Done in 3.2 seconds.', $result);
    }

    /**
     * @test
     */
    public function testErrorPlaceholder() {
        $formatter = new StatusMessageFormatter();

        $result = $formatter->format(Status::ERROR, ['error' => 'Connection timeout']);
        $this->assertEquals('Something went wrong: Connection timeout', $result);
    }

    /**
     * @test
     */
    public function testSetCustomTemplate() {
        $formatter = new StatusMessageFormatter();
        $formatter->setTemplate(Status::TOOL_CALLING, 'Fetching data with {tool}...');

        $result = $formatter->format(Status::TOOL_CALLING, ['tool' => 'api_call']);
        $this->assertEquals('Fetching data with api_call...', $result);
    }

    /**
     * @test
     */
    public function testSetMultipleTemplates() {
        $formatter = new StatusMessageFormatter();
        $formatter->setTemplates([
            Status::PREPARING => 'Starting...',
            Status::COMPLETED => 'Finished!',
        ]);

        $this->assertEquals('Starting...', $formatter->format(Status::PREPARING));
        $this->assertEquals('Finished!', $formatter->format(Status::COMPLETED));
    }

    /**
     * @test
     */
    public function testUnknownStatusReturnsStatusName() {
        $formatter = new StatusMessageFormatter();

        $result = $formatter->format('unknown_status');
        $this->assertEquals('unknown_status', $result);
    }

    /**
     * @test
     */
    public function testDotNotationInPlaceholders() {
        $formatter = new StatusMessageFormatter();
        $formatter->setTemplate('custom', 'City: {arguments.city}');

        $result = $formatter->format('custom', ['arguments' => ['city' => 'Dubai']]);
        $this->assertEquals('City: Dubai', $result);
    }

    /**
     * @test
     */
    public function testUnresolvedPlaceholderKeptAsIs() {
        $formatter = new StatusMessageFormatter();
        $formatter->setTemplate('custom', 'Hello {name}!');

        $result = $formatter->format('custom', []);
        $this->assertEquals('Hello {name}!', $result);
    }

    /**
     * @test
     */
    public function testArrayValueInPlaceholder() {
        $formatter = new StatusMessageFormatter();
        $formatter->setTemplate('custom', 'Data: {data}');

        $result = $formatter->format('custom', ['data' => ['a', 'b']]);
        $this->assertEquals('Data: ["a","b"]', $result);
    }

    /**
     * @test
     */
    public function testMethodChaining() {
        $formatter = new StatusMessageFormatter();
        $result = $formatter->setTemplate(Status::PREPARING, 'Custom');

        $this->assertSame($formatter, $result);
    }
}
