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
/**
 * Generates deterministic cache keys for AI requests.
 *
 * Keys are based on the provider, model, messages/input, and relevant options.
 * The same request will always produce the same key.
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
