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
 * Strict fingerprint strategy: URL + full normalized request body hash.
 *
 * Every field in the request body (including temperature, max_tokens, tools,
 * JSON schema, etc.) contributes to the fingerprint. Use this when you need
 * different fixtures for requests that differ only in generation parameters.
 *
 * @author Ibrahim
 */
class FullBodyFingerprintStrategy implements FingerprintStrategyInterface {
    /**
     * {@inheritdoc}
     */
    public function fingerprint(HttpRequest $request): string {
        $body = json_decode($request->getBody(), true) ?? [];
        $normalized = json_encode($this->sortRecursive($body), \JSON_UNESCAPED_UNICODE) ?: '';

        return hash('sha256', $request->getMethod().':'.$request->getUrl().':'.$normalized);
    }

    /**
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
