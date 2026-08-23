<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Http\Recording;

use WebFiori\Ai\Http\HttpRequest;

/**
 * Default fingerprint strategy: URL + hash of the messages array.
 *
 * Generation parameters (temperature, max_tokens, top_p, etc.) are excluded
 * from the fingerprint. This means two requests with the same conversation
 * but different sampling params will match the same fixture.
 *
 * @author Ibrahim
 */
class MessagesFingerprintStrategy implements FingerprintStrategyInterface {
    /**
     * {@inheritdoc}
     */
    public function fingerprint(HttpRequest $request): string {
        $url = $request->getUrl();
        $body = json_decode($request->getBody(), true) ?? [];

        // Extract the messages/contents/input array (varies by provider)
        $messages = $body['messages']       // OpenAI, Anthropic
            ?? $body['contents']            // Google generateContent
            ?? $body['input']               // Google Interactions API
            ?? $body['system'] ?? [];       // fallback

        $normalized = json_encode($this->sortRecursive($messages), \JSON_UNESCAPED_UNICODE) ?: '';

        return hash('sha256', $request->getMethod().':'.$url.':'.$normalized);
    }

    /**
     * Recursively sorts array keys for deterministic serialization.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    private function sortRecursive(mixed $data): mixed {
        if (!is_array($data)) {
            return $data;
        }

        ksort($data);

        return array_map([$this, 'sortRecursive'], $data);
    }
}
