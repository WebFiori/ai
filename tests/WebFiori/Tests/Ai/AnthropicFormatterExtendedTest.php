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
use WebFiori\Ai\Http\FakeHttpClient;
use WebFiori\Ai\Http\HttpResponse;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\Anthropic\AnthropicClient;
use WebFiori\Ai\Provider\Anthropic\AnthropicClientConfig;
use WebFiori\Ai\Tool\AnthropicBuiltInTool;

/**
 * Complementary offline tests for AnthropicFormatter, focusing on multi-modal
 * content-part formatting and built-in tool formatting branches that the basic
 * AnthropicClientTest does not exercise.
 */
class AnthropicFormatterExtendedTest extends TestCase {
    private function client(): AnthropicClient {
        return new AnthropicClient(new AnthropicClientConfig(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
        ));
    }

    private function okResponse(): HttpResponse {
        return new HttpResponse(200, [], json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ]));
    }

    public function testFormatsMultiModalContentParts(): void {
        $http = new FakeHttpClient();
        $http->addResponse($this->okResponse());

        $client = $this->client();
        $client->setHttpClient($http);

        $png = base64_encode('fake-png-bytes');

        $client->chat([
            new Message('user', [
                ContentPart::text('Look at these.'),
                ContentPart::imageBase64($png, 'image/png'),
                ContentPart::document(base64_encode('%PDF-1.4'), 'application/pdf'),
                ContentPart::document(base64_encode('plain text'), 'text/plain'),
            ]),
        ]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $content = $body['messages'][0]['content'];

        $types = array_column($content, 'type');
        $this->assertContains('text', $types);
        $this->assertContains('image', $types);
        $this->assertContains('document', $types);

        // The image part carries a base64 source with the right media type.
        foreach ($content as $block) {
            if ($block['type'] === 'image') {
                $this->assertSame('base64', $block['source']['type']);
                $this->assertSame('image/png', $block['source']['media_type']);
            }
        }
    }

    public function testFormatsDocumentImageAsImageBlock(): void {
        $http = new FakeHttpClient();
        $http->addResponse($this->okResponse());

        $client = $this->client();
        $client->setHttpClient($http);

        $client->chat([
            new Message('user', [
                ContentPart::document(base64_encode('img'), 'image/jpeg'),
            ]),
        ]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
    }

    public function testBuiltInBashToolIsFormatted(): void {
        $http = new FakeHttpClient();
        $http->addResponse($this->okResponse());

        $client = $this->client();
        $client->setHttpClient($http);

        $client->chat([new Message('user', 'run a command')], [
            'built_in_tools' => [AnthropicBuiltInTool::BASH],
        ]);

        $body = json_decode($http->getLastRequest()->getBody(), true);
        $this->assertArrayHasKey('tools', $body);
        $names = array_column($body['tools'], 'name');
        $this->assertContains(AnthropicBuiltInTool::BASH->getValue(), $names);
    }
}
