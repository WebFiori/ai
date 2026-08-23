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

use WebFiori\Ai\Http\HttpClientInterface;
use WebFiori\Ai\Http\HttpRequest;
use WebFiori\Ai\Http\HttpResponse;

/**
 * HTTP client that replays recorded fixture files instead of making real calls.
 *
 * Loads all fixtures from a directory on first use and matches incoming
 * requests by fingerprint. Throws FixtureNotFoundException if no matching
 * fixture is found — there is no silent fallback.
 *
 * ```php
 * $replayer = new ReplayHttpClient(__DIR__ . '/fixtures');
 * $provider->setHttpClient($replayer);
 *
 * // No real API calls — responses loaded from fixture files
 * $response = $provider->chat($messages);
 * ```
 *
 * @author Ibrahim
 */
class ReplayHttpClient implements HttpClientInterface {
    /**
     * The fixture catalog.
     *
     * @var FixtureCatalog
     */
    private FixtureCatalog $catalog;

    /**
     * The fingerprint strategy.
     *
     * @var FingerprintStrategyInterface
     */
    private FingerprintStrategyInterface $fingerprint;

    /**
     * Creates a new ReplayHttpClient instance.
     *
     * @param string $path Directory containing fixture files.
     * @param FingerprintStrategyInterface|null $fingerprint Matching strategy.
     *        Must match the strategy used when recording.
     *
     * @throws \InvalidArgumentException If the path does not exist.
     */
    public function __construct(
        string $path,
        ?FingerprintStrategyInterface $fingerprint = null
    ) {
        $this->catalog = new FixtureCatalog($path);
        $this->fingerprint = $fingerprint ?? new MessagesFingerprintStrategy();
    }

    /**
     * Returns the fixture catalog.
     *
     * @return FixtureCatalog
     */
    public function getCatalog(): FixtureCatalog {
        return $this->catalog;
    }

    /**
     * {@inheritdoc}
     *
     * Returns the recorded response for this request.
     *
     * @throws FixtureNotFoundException If no matching fixture is found.
     */
    public function send(HttpRequest $request): HttpResponse {
        $fp = $this->fingerprint->fingerprint($request);
        $fixture = $this->catalog->find($fp);

        if ($fixture === null) {
            throw new FixtureNotFoundException($request, $fp, $this->catalog);
        }

        if ($fixture->isStreaming()) {
            throw new \LogicException(
                "Fixture '{$fixture->getName()}' is a streaming fixture. ".
                "Use sendStreaming() instead of send()."
            );
        }

        return $fixture->getResponse();
    }

    /**
     * {@inheritdoc}
     *
     * Replays the recorded SSE chunks for this request.
     *
     * @throws FixtureNotFoundException If no matching fixture is found.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $fp = $this->fingerprint->fingerprint($request);
        $fixture = $this->catalog->find($fp);

        if ($fixture === null) {
            throw new FixtureNotFoundException($request, $fp, $this->catalog);
        }

        if (!$fixture->isStreaming()) {
            // Non-streaming fixture used in streaming context —
            // replay the body as a single chunk for compatibility
            $response = $fixture->getResponse();
            $onChunk($response->getBody());

            return;
        }

        foreach ($fixture->getChunks() as $chunk) {
            $onChunk($chunk);
        }
    }
}
