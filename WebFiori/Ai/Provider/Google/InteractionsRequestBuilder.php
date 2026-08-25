<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Google;

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Message;
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Builds HTTP requests for the Google Interactions API.
 *
 * The Interactions API (used by gemini-3.x+) differs from generateContent:
 * - Model is in the request body, not the URL path
 * - Tools are flat objects with type: 'function'
 * - No systemInstruction field — system text passed via system_instruction param
 * - store: false for stateless mode
 *
 * @author Ibrahim
 */
class InteractionsRequestBuilder {
    /**
     * The message formatter.
     *
     * @var InteractionsMessageFormatter
     */
    private InteractionsMessageFormatter $formatter;

    /**
     * Creates a new InteractionsRequestBuilder instance.
     */
    public function __construct() {
        $this->formatter = new InteractionsMessageFormatter();
    }

    /**
     * Builds the HTTP request for a chat completion via the Interactions API.
     *
     * @param string $model The model identifier (e.g., 'gemini-3.5-flash').
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options (temperature, tools, etc.).
     * @param string $endpointUrl The full Interactions API endpoint URL.
     * @param array<string, string> $headers HTTP headers (auth, Content-Type).
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildChatRequest(
        string $model,
        array $messages,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        $body = $this->buildBody($model, $messages, $options, stream: false);

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
    }

    /**
     * Builds the Interactions API endpoint URL for the Gemini API.
     *
     * @param string|null $apiKey Optional API key for query parameter auth.
     * @param bool $stream Whether to use the streaming endpoint.
     *
     * @return string The full endpoint URL.
     */
    public function buildGeminiEndpoint(?string $apiKey, bool $stream = false): string {
        // Both streaming and non-streaming use the same /interactions endpoint.
        // Streaming is controlled by `stream: true` in the request body.
        $url = 'https://generativelanguage.googleapis.com/v1beta/interactions';

        if ($apiKey !== null && $apiKey !== '') {
            $url .= '?key='.$apiKey;
        }

        return $url;
    }

    /**
     * Builds the HTTP request for a streaming chat via the Interactions API.
     *
     * @param string $model The model identifier.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param string $endpointUrl The full Interactions API streaming endpoint URL.
     * @param array<string, string> $headers HTTP headers.
     *
     * @return HttpRequest The HTTP request to send.
     */
    public function buildStreamChatRequest(
        string $model,
        array $messages,
        array $options,
        string $endpointUrl,
        array $headers
    ): HttpRequest {
        $body = $this->buildBody($model, $messages, $options, stream: true);

        return new HttpRequest('POST', $endpointUrl, $headers, json_encode($body));
    }

    /**
     * Builds the Interactions API endpoint URL for Vertex AI.
     *
     * @param string $projectId GCP project ID.
     * @param string $location GCP region.
     * @param bool $stream Whether to use the streaming endpoint.
     *
     * @return string The full endpoint URL.
     */
    public function buildVertexEndpoint(string $projectId, string $location, bool $stream = false): string {
        $path = $stream ? 'interactions:stream' : 'interactions';

        if ($location === 'global') {
            return sprintf(
                'https://aiplatform.googleapis.com/v1beta1/projects/%s/locations/global/%s',
                $projectId,
                $path
            );
        }

        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1beta1/projects/%s/locations/%s/%s',
            $location,
            $projectId,
            $location,
            $path
        );
    }

    /**
     * Formats tools for the Interactions API.
     *
     * The Interactions API uses flat tool objects with type: 'function',
     * unlike generateContent which nests them under 'functionDeclarations'.
     *
     * @param ToolInterface[] $tools The tools to format.
     *
     * @return array<int, array<string, mixed>> The formatted tools array.
     */
    public function formatTools(array $tools): array {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'type' => 'function',
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParameters(),
            ];
        }

        return $formatted;
    }

    /**
     * Builds the full request body for an Interactions API request.
     *
     * @param string $model The model identifier.
     * @param Message[] $messages The conversation messages.
     * @param array<string, mixed> $options Additional options.
     * @param bool $stream Whether this is a streaming request.
     *
     * @return array<string, mixed> The request body.
     */
    private function buildBody(
        string $model,
        array $messages,
        array $options,
        bool $stream
    ): array {
        $body = [
            'model' => $model,
            'input' => $this->formatter->format($messages),
            'store' => false, // Stateless mode — client manages history
        ];

        // Streaming: add stream:true to body (same endpoint, different body flag)
        if ($stream) {
            $body['stream'] = true;
        }

        // System instruction
        $systemInstruction = $this->formatter->extractSystemInstruction($messages);

        if ($systemInstruction !== null) {
            $body['system_instruction'] = $systemInstruction;
        }

        // Tools in flat format
        $tools = $options[ChatOption::TOOLS] ?? [];

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        // Generation config
        $generationConfig = $this->buildGenerationConfig($options);

        if (!empty($generationConfig)) {
            $body['generation_config'] = $generationConfig;
        }

        // Stateful mode: include previous interaction ID if provided
        if (isset($options[ChatOption::PREVIOUS_INTERACTION_ID])) {
            $body['previous_interaction_id'] = $options[ChatOption::PREVIOUS_INTERACTION_ID];
            // In stateful mode, don't include full history in input
            unset($body['store']);
        }

        return $body;
    }

    /**
     * Builds the generation_config section for the Interactions API.
     *
     * @param array<string, mixed> $options The request options.
     *
     * @return array<string, mixed> The generation config, or empty array if none.
     */
    private function buildGenerationConfig(array $options): array {
        $config = [];

        if (isset($options[ChatOption::TEMPERATURE])) {
            $config['temperature'] = $options[ChatOption::TEMPERATURE];
        }

        if (isset($options[ChatOption::MAX_TOKENS])) {
            $config['max_output_tokens'] = $options[ChatOption::MAX_TOKENS];
        }

        if (isset($options[ChatOption::TOP_P])) {
            $config['top_p'] = $options[ChatOption::TOP_P];
        }

        if (isset($options[ChatOption::STOP])) {
            $config['stop_sequences'] = is_array($options[ChatOption::STOP])
                ? $options[ChatOption::STOP]
                : [$options[ChatOption::STOP]];
        }

        if (isset($options['thinking_level'])) {
            $config['thinking_level'] = $options['thinking_level'];
        }

        return $config;
    }
}
