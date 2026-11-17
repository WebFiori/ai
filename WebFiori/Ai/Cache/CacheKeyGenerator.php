<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Cache;

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Tool\BuiltInToolInterface;
use WebFiori\Ai\Tool\ToolInterface;
/**
 * Generates deterministic cache keys for AI requests.
 *
 * Keys are based on the provider, model, messages/input, and relevant options.
 * For chat requests this includes the tool definitions (name, description and
 * parameter schema, in the order given) and the built-in tools, because they
 * change what the model can answer. The same request will always produce the
 * same key.
 *
 * @author Ibrahim
 */
class CacheKeyGenerator {
    /**
     * Generates a cache key for a chat request.
     *
     * @param string $provider The provider name.
     * @param string $model The model identifier.
     * @param array $messages The conversation messages.
     * @param array<string, mixed> $options Request options affecting output.
     *
     * @return string The cache key.
     */
    public function forChat(string $provider, string $model, array $messages, array $options): string {
        $ctx = hash_init('sha256');

        hash_update($ctx, $provider);
        hash_update($ctx, "\0");
        hash_update($ctx, $model);
        hash_update($ctx, "\0");

        foreach ($messages as $message) {
            $this->hashMessage($ctx, $message);
        }

        $relevantOptions = $this->extractRelevantOptions($options);
        hash_update($ctx, json_encode($relevantOptions));

        // Added only when present, so requests without tools keep the key
        // they had before tool definitions were part of it.
        $tools = $this->describeTools($options[ChatOption::TOOLS] ?? []);

        if ($tools !== []) {
            hash_update($ctx, "\0tools:");
            hash_update($ctx, $this->encode($tools));
        }

        $builtInTools = $this->describeBuiltInTools($options['built_in_tools'] ?? []);

        if ($builtInTools !== []) {
            hash_update($ctx, "\0built_in_tools:");
            hash_update($ctx, $this->encode($builtInTools));
        }

        return 'chat_'.hash_final($ctx);
    }

    /**
     * Generates a cache key for an embedding request.
     *
     * @param string $provider The provider name.
     * @param string $model The model identifier.
     * @param string|array $input The text input(s) to embed.
     * @param array<string, mixed> $options Request options.
     *
     * @return string The cache key.
     */
    public function forEmbedding(string $provider, string $model, string|array $input, array $options): string {
        $data = [
            'provider' => $provider,
            'model' => $model,
            'input' => $input,
            'dimensions' => $options[ChatOption::DIMENSIONS] ?? null,
        ];

        return 'embed_'.hash('sha256', json_encode($data));
    }

    /**
     * Describes built-in tools (such as web search) for hashing.
     *
     * @param mixed $builtInTools The `built_in_tools` option value.
     *
     * @return array<int, string> One entry per built-in tool, in the order given.
     */
    private function describeBuiltInTools(mixed $builtInTools): array {
        if (!is_array($builtInTools)) {
            return [];
        }

        $described = [];

        foreach ($builtInTools as $tool) {
            if ($tool instanceof BuiltInToolInterface) {
                $described[] = get_class($tool).':'.$tool->getValue();
            }
        }

        return $described;
    }

    /**
     * Describes tool definitions for hashing.
     *
     * Order is kept as given, because it can influence which tool the model
     * picks. A different order is a cache miss, never a wrong hit.
     *
     * @param mixed $tools The `tools` option value.
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    private function describeTools(mixed $tools): array {
        if (!is_array($tools)) {
            return [];
        }

        $described = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $described[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ];
            }
        }

        return $described;
    }

    /**
     * Encodes a value to a string for hashing.
     *
     * Falls back to serialize() when the value cannot be JSON encoded (for
     * example invalid UTF-8), so two different values never hash the same
     * empty string.
     *
     * @param mixed $value The value to encode.
     *
     * @return string The encoded value.
     */
    private function encode(mixed $value): string {
        $json = json_encode($value);

        return $json === false ? serialize($value) : $json;
    }

    /**
     * Extracts options that affect the model output.
     *
     * @param array<string, mixed> $options The full options array.
     *
     * @return array<string, mixed> Options relevant for caching.
     */
    private function extractRelevantOptions(array $options): array {
        $relevant = [];
        $keys = [
            'temperature',
            'max_tokens',
            'top_p',
            'frequency_penalty',
            'presence_penalty',
            'stop',
            'n',
        ];

        foreach ($keys as $key) {
            if (isset($options[$key])) {
                $relevant[$key] = $options[$key];
            }
        }

        // Options that change the request body or the output format. Added
        // only when set, so requests without them keep their previous key.
        if (!empty($options[ChatOption::JSON_MODE])) {
            $relevant[ChatOption::JSON_MODE] = true;
        }

        foreach ([
            ChatOption::JSON_SCHEMA,
            ChatOption::PREVIOUS_INTERACTION_ID,
            'thinking_level',
            'detail',
        ] as $key) {
            if (isset($options[$key])) {
                $relevant[$key] = $options[$key];
            }
        }

        ksort($relevant);

        return $relevant;
    }

    /**
     * Feeds a single message's identifying state into a hash context.
     *
     * Hashes the role, content, any tool calls (assistant messages), and any
     * tool result (tool messages). Field separators are written between values
     * so that distinct field boundaries cannot collide.
     *
     * @param \HashContext $ctx The incremental hash context to update.
     * @param mixed $message The message to hash. Expected to be a Message instance.
     */
    private function hashMessage(\HashContext $ctx, $message): void {
        hash_update($ctx, $message->getRole());
        hash_update($ctx, "\0");
        hash_update($ctx, $message->getContent());
        hash_update($ctx, "\0");

        foreach ($message->getToolCalls() as $toolCall) {
            hash_update($ctx, 'tc:');
            hash_update($ctx, $toolCall->getId());
            hash_update($ctx, "\0");
            hash_update($ctx, $toolCall->getName());
            hash_update($ctx, "\0");
            hash_update($ctx, json_encode($toolCall->getArguments()));
            hash_update($ctx, "\0");
        }

        $toolResult = $message->getToolResult();

        if ($toolResult !== null) {
            hash_update($ctx, 'tr:');
            hash_update($ctx, $toolResult->getToolCallId());
            hash_update($ctx, "\0");
            hash_update($ctx, $toolResult->getName());
            hash_update($ctx, "\0");
            hash_update($ctx, $toolResult->getContent());
            hash_update($ctx, "\0");
        }
    }
}
