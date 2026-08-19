<?php

namespace WebFiori\Tests\Ai\Tool;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Exception\UnsupportedFeatureException;
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Provider\Google\GoogleClient;
use WebFiori\Ai\Provider\Google\GoogleClientConfig;
use WebFiori\Ai\Provider\Google\GoogleApi;
use WebFiori\Ai\Provider\Google\GoogleApiVersion;

use WebFiori\Ai\Provider\OpenAI\OpenAIClient;
use WebFiori\Ai\Provider\OpenAI\OpenAIClientConfig;
use WebFiori\Ai\Tool\AnthropicBuiltInTool;
use WebFiori\Ai\Tool\GoogleBuiltInTool;
use WebFiori\Ai\Tool\OpenAIBuiltInTool;
use WebFiori\Ai\Tool\Tool;

/**
 * Integration tests for provider-native built-in tools.
 */
class BuiltInToolIntegrationTest extends TestCase {
    // =========================================================================
    // Google
    // =========================================================================

    public function testGoogleSearchSentInRequest(): void {
        $fakeHttp = $this->fakeHttpWithOkResponse('google');
        $provider = $this->googleGeminiClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'What happened in the news today?')],
            ['built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $this->assertArrayHasKey('tools', $body);

        // googleSearch should appear as a separate tools entry
        $toolValues = array_column($body['tools'], null);
        $hasGoogleSearch = false;

        foreach ($body['tools'] as $entry) {
            if (isset($entry['googleSearch'])) {
                $hasGoogleSearch = true;

                break;
            }
        }

        $this->assertTrue($hasGoogleSearch, 'googleSearch should be present in tools');
    }

    public function testGoogleCodeExecutionSentInRequest(): void {
        $fakeHttp = $this->fakeHttpWithOkResponse('google');
        $provider = $this->googleGeminiClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'Calculate 42 * 17')],
            ['built_in_tools' => [GoogleBuiltInTool::CODE_EXECUTION]]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $hasCodeExecution = false;

        foreach ($body['tools'] as $entry) {
            if (isset($entry['codeExecution'])) {
                $hasCodeExecution = true;

                break;
            }
        }

        $this->assertTrue($hasCodeExecution, 'codeExecution should be present in tools');
    }

    public function testGoogleSearchWithCustomToolOnGeminiApi(): void {
        // Gemini API allows both googleSearch + functionDeclarations
        $fakeHttp = $this->fakeHttpWithOkResponse('google');
        $provider = $this->googleGeminiClient();
        $provider->setHttpClient($fakeHttp);

        $customTool = $this->makeSimpleTool();

        $provider->chat(
            [new Message('user', 'Search and then use my tool')],
            [
                'tools'          => [$customTool],
                'built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH],
            ]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $this->assertArrayHasKey('tools', $body);

        $hasGoogleSearch   = false;
        $hasFunctionDecl   = false;

        foreach ($body['tools'] as $entry) {
            if (isset($entry['googleSearch'])) {
                $hasGoogleSearch = true;
            }

            if (isset($entry['functionDeclarations'])) {
                $hasFunctionDecl = true;
            }
        }

        $this->assertTrue($hasGoogleSearch, 'googleSearch should be present');
        $this->assertTrue($hasFunctionDecl, 'functionDeclarations should be present');
    }

    public function testGoogleGroundingFallbackToSearchEntryPoint(): void {
        // When grounding is active, text may come from searchEntryPoint.renderedContent
        // instead of parts[].text — parser should fall back to it
        $groundingResponse = json_encode([
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [[]]],
                'finishReason' => 'STOP',
                'groundingMetadata' => [
                    'searchEntryPoint' => [
                        'renderedContent' => 'PHP 8.4 is the latest stable version.',
                    ],
                ],
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $fakeHttp = new FakeHttpClient();
        $fakeHttp->addResponse(new HttpResponse(200, [], $groundingResponse));

        $provider = $this->googleGeminiClient();
        $provider->setHttpClient($fakeHttp);

        $response = $provider->chat(
            [new Message('user', 'What is the latest PHP version?')],
            ['built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]]
        );

        $this->assertEquals(
            'PHP 8.4 is the latest stable version.',
            $response->getMessage()->getContent()
        );
    }

    public function testGoogleSearchWithCustomToolOnVertexAiThrows(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageMatches('/Vertex AI/');

        $fakeHttp = $this->fakeHttpWithOkResponse('google');
        $provider = new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            projectId: 'test-project',
            accessToken: 'test-token',
            api: GoogleApi::VERTEX_AI,
        ));
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'test')],
            [
                'tools'          => [$this->makeSimpleTool()],
                'built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH],
            ]
        );
    }

    public function testGoogleWrongProviderEnumThrows(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageMatches('/GoogleClient/');

        $fakeHttp = $this->fakeHttpWithOkResponse('google');
        $provider = $this->googleGeminiClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'test')],
            ['built_in_tools' => [OpenAIBuiltInTool::WEB_SEARCH]] // Wrong enum
        );
    }

    // =========================================================================
    // OpenAI
    // =========================================================================

    public function testOpenAIWebSearchSentInRequest(): void {
        $fakeHttp = $this->fakeHttpWithOkResponse('openai');
        $provider = $this->openAIClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'What is the latest news?')],
            ['built_in_tools' => [OpenAIBuiltInTool::WEB_SEARCH]]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $this->assertArrayHasKey('tools', $body);

        $types = array_column($body['tools'], 'type');
        $this->assertContains('web_search_preview', $types);
    }

    public function testOpenAIBuiltInAndCustomToolsMerged(): void {
        $fakeHttp = $this->fakeHttpWithOkResponse('openai');
        $provider = $this->openAIClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'test')],
            [
                'tools'          => [$this->makeSimpleTool()],
                'built_in_tools' => [OpenAIBuiltInTool::WEB_SEARCH],
            ]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $this->assertCount(2, $body['tools']);

        $types = array_column($body['tools'], 'type');
        $this->assertContains('function', $types);
        $this->assertContains('web_search_preview', $types);
    }

    public function testOpenAIWrongProviderEnumThrows(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageMatches('/OpenAIClient/');

        $fakeHttp = $this->fakeHttpWithOkResponse('openai');
        $provider = $this->openAIClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'test')],
            ['built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]] // Wrong enum
        );
    }

    // =========================================================================
    // Anthropic
    // =========================================================================

    public function testAnthropicBashToolSentInRequest(): void {
        $fakeHttp = $this->fakeHttpWithOkResponse('anthropic');
        $provider = $this->anthropicClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'Run a command for me')],
            ['built_in_tools' => [AnthropicBuiltInTool::BASH]]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $this->assertArrayHasKey('tools', $body);

        $types = array_column($body['tools'], 'type');
        $this->assertContains('bash_20241022', $types);
    }

    public function testAnthropicComputerToolHasDimensions(): void {
        $fakeHttp = $this->fakeHttpWithOkResponse('anthropic');
        $provider = $this->anthropicClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'Use the computer')],
            ['built_in_tools' => [AnthropicBuiltInTool::COMPUTER]]
        );

        $body = json_decode($fakeHttp->getLastRequest()->getBody(), true);

        $computerTool = null;

        foreach ($body['tools'] as $tool) {
            if ($tool['type'] === 'computer_20241022') {
                $computerTool = $tool;

                break;
            }
        }

        $this->assertNotNull($computerTool, 'computer tool should be present');
        $this->assertArrayHasKey('display_width_px', $computerTool);
        $this->assertArrayHasKey('display_height_px', $computerTool);
        $this->assertArrayHasKey('display_number', $computerTool);
    }

    public function testAnthropicWrongProviderEnumThrows(): void {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageMatches('/AnthropicClient/');

        $fakeHttp = $this->fakeHttpWithOkResponse('anthropic');
        $provider = $this->anthropicClient();
        $provider->setHttpClient($fakeHttp);

        $provider->chat(
            [new Message('user', 'test')],
            ['built_in_tools' => [GoogleBuiltInTool::GOOGLE_SEARCH]] // Wrong enum
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fakeHttpWithOkResponse(string $provider): FakeHttpClient {
        $fakeHttp = new FakeHttpClient();

        if ($provider === 'openai') {
            $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
                'model'   => 'gpt-4o',
                'choices' => [[
                    'message'       => ['role' => 'assistant', 'content' => 'OK'],
                    'finish_reason' => 'stop',
                ]],
            ])));
        } elseif ($provider === 'google') {
            $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
                'candidates' => [[
                    'content'      => ['role' => 'model', 'parts' => [['text' => 'OK']]],
                    'finishReason' => 'STOP',
                ]],
            ])));
        } else {
            $fakeHttp->addResponse(new HttpResponse(200, [], json_encode([
                'id'         => 'msg_01',
                'type'       => 'message',
                'role'       => 'assistant',
                'model'      => 'claude-3-5-sonnet-20241022',
                'content'    => [['type' => 'text', 'text' => 'OK']],
                'stop_reason' => 'end_turn',
                'usage'      => ['input_tokens' => 5, 'output_tokens' => 2],
            ])));
        }

        return $fakeHttp;
    }

    private function googleGeminiClient(): GoogleClient {
        return new GoogleClient(new GoogleClientConfig(
            model: 'gemini-2.5-flash',
            accessToken: 'test-token',
        ));
    }

    private function openAIClient(): OpenAIClient {
        return new OpenAIClient(new OpenAIClientConfig(apiKey: 'sk-test', model: 'gpt-4o'));
    }

    private function anthropicClient(): AnthropicClient {
        return new AnthropicClient(new AnthropicClientConfig(apiKey: 'sk-ant-test', model: 'claude-3-5-sonnet-20241022'));
    }

    private function makeSimpleTool(): Tool {
        return new Tool(
            'my_tool',
            'A simple test tool',
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
            fn($args) => 'result',
        );
    }
}
