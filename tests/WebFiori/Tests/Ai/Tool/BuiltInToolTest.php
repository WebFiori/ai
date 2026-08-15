<?php

namespace WebFiori\Tests\Ai\Tool;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Tool\AnthropicBuiltInTool;
use WebFiori\Ai\Tool\BuiltInToolInterface;
use WebFiori\Ai\Tool\GoogleBuiltInTool;
use WebFiori\Ai\Tool\OpenAIBuiltInTool;

class BuiltInToolTest extends TestCase {
    // --- GoogleBuiltInTool ---

    public function testGoogleBuiltInToolImplementsInterface(): void {
        $this->assertInstanceOf(BuiltInToolInterface::class, GoogleBuiltInTool::GOOGLE_SEARCH);
    }

    public function testGoogleBuiltInToolValues(): void {
        $this->assertEquals('google_search', GoogleBuiltInTool::GOOGLE_SEARCH->getValue());
        $this->assertEquals('code_execution', GoogleBuiltInTool::CODE_EXECUTION->getValue());
        $this->assertEquals('url_context', GoogleBuiltInTool::URL_CONTEXT->getValue());
    }

    public function testGoogleBuiltInToolCasesExist(): void {
        $cases = GoogleBuiltInTool::cases();
        $values = array_map(fn($c) => $c->getValue(), $cases);

        $this->assertContains('google_search', $values);
        $this->assertContains('code_execution', $values);
        $this->assertContains('url_context', $values);
    }

    // --- AnthropicBuiltInTool ---

    public function testAnthropicBuiltInToolImplementsInterface(): void {
        $this->assertInstanceOf(BuiltInToolInterface::class, AnthropicBuiltInTool::BASH);
    }

    public function testAnthropicBuiltInToolValues(): void {
        $this->assertEquals('computer', AnthropicBuiltInTool::COMPUTER->getValue());
        $this->assertEquals('bash', AnthropicBuiltInTool::BASH->getValue());
        $this->assertEquals('text_editor', AnthropicBuiltInTool::TEXT_EDITOR->getValue());
    }

    // --- OpenAIBuiltInTool ---

    public function testOpenAIBuiltInToolImplementsInterface(): void {
        $this->assertInstanceOf(BuiltInToolInterface::class, OpenAIBuiltInTool::WEB_SEARCH);
    }

    public function testOpenAIBuiltInToolValues(): void {
        $this->assertEquals('web_search_preview', OpenAIBuiltInTool::WEB_SEARCH->getValue());
        $this->assertEquals('code_interpreter', OpenAIBuiltInTool::CODE_INTERPRETER->getValue());
        $this->assertEquals('file_search', OpenAIBuiltInTool::FILE_SEARCH->getValue());
    }
}
