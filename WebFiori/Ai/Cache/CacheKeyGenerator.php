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
        $relevantOptions = $this->extractRelevantOptions($options);

        $data = [
            'provider' => $provider,
            'model' => $model,
            'messages' => $this->serializeMessages($messages),
            'options' => $relevantOptions,
        ];

        return 'chat_'.hash('sha256', json_encode($data));
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
            'dimensions' => $options['dimensions'] ?? null,
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
     * Serializes messages into a consistent format for hashing.
     *
     * @param array $messages The messages to serialize.
     *
     * @return array The serialized messages.
     */
    private function serializeMessages(array $messages): array {
        $serialized = [];

        foreach ($messages as $message) {
            $serialized[] = [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ];
        }

        return $serialized;
    }
}
