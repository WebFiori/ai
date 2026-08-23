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
 * HTTP client decorator that records responses to fixture files.
 *
 * Wraps any existing HTTP client and transparently records each request/
 * response pair as a JSON fixture file. Recorded fixtures can later be
 * replayed by ReplayHttpClient without hitting the real API.
 *
 * API keys are automatically scrubbed from recorded request headers.
 *
 * ```php
 * $recorder = new RecordingHttpClient(
 *     inner: new CurlHttpClient(),
 *     path: __DIR__ . '/fixtures',
 * );
 * $provider->setHttpClient($recorder);
 *
 * // Make real API calls — responses are saved to disk
 * $provider->chat($messages);
 * ```
 *
 * @author Ibrahim
 */
class RecordingHttpClient implements HttpClientInterface {
    /**
     * The fixture catalog managing fixture files.
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
     * The inner HTTP client being decorated.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $inner;

    /**
     * Headers to scrub from recorded requests.
     *
     * @var string[]
     */
    private array $scrubHeaders = [
        'authorization',
        'x-api-key',
        'x-goog-api-key',
    ];

    /**
     * Creates a new RecordingHttpClient instance.
     *
     * @param HttpClientInterface $inner The real HTTP client to wrap.
     * @param string $path Directory where fixtures will be saved.
     * @param FingerprintStrategyInterface|null $fingerprint Matching strategy.
     *        Defaults to MessagesFingerprintStrategy.
     *
     * @throws \InvalidArgumentException If the path does not exist.
     */
    public function __construct(
        HttpClientInterface $inner,
        string $path,
        ?FingerprintStrategyInterface $fingerprint = null
    ) {
        $this->inner = $inner;
        $this->catalog = new FixtureCatalog($path);
        $this->fingerprint = $fingerprint ?? new MessagesFingerprintStrategy();
    }

    /**
     * Adds headers to scrub from recorded request headers.
     *
     * @param string[] $headers Header names (case-insensitive).
     */
    public function addScrubHeaders(array $headers): void {
        foreach ($headers as $header) {
            $this->scrubHeaders[] = strtolower($header);
        }
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
     * Sends the request via the inner client and records the response.
     */
    public function send(HttpRequest $request): HttpResponse {
        $response = $this->inner->send($request);

        $fp = $this->fingerprint->fingerprint($request);
        $name = $this->buildName($request);

        $fixture = new HttpFixture($fp, $this->scrubResponse($response), $name);
        $this->catalog->save($fixture);

        return $response; // return original, not scrubbed
    }
    /**
     * {@inheritdoc}
     *
     * Streams the request via the inner client and records all chunks.
     */
    public function sendStreaming(HttpRequest $request, callable $onChunk): void {
        $fp = $this->fingerprint->fingerprint($request);
        $name = $this->buildName($request);
        $chunks = [];

        $this->inner->sendStreaming($request, function (string $chunk) use ($onChunk, &$chunks)
        {
            $chunks[] = $chunk;
            $onChunk($chunk);
        });

        $fixture = HttpFixture::streaming($fp, $chunks, $name);
        $this->catalog->save($fixture);
    }

    /**
     * Builds a human-readable name for a fixture based on the request URL.
     *
     * @param HttpRequest $request The request.
     *
     * @return string The fixture name.
     */
    private function buildName(HttpRequest $request): string {
        $url = parse_url($request->getUrl());
        $host = $url['host'] ?? 'unknown';
        $path = trim($url['path'] ?? '', '/');

        // Extract provider name from host
        $provider = match (true) {
            str_contains($host, 'openai') => 'openai',
            str_contains($host, 'anthropic') => 'anthropic',
            str_contains($host, 'aiplatform') => 'vertex',   // before 'googleapis'
            str_contains($host, 'googleapis') => 'google',
            str_contains($host, 'amazonaws') => 'bedrock',
            default => $host,
        };

        // Last path segment as endpoint name
        $segments = explode('/', $path);
        $endpoint = end($segments) ?: 'request';
        $endpoint = str_replace(['.', '-', ':'], '_', $endpoint);

        return "{$provider}_{$endpoint}";
    }

    /**
     * Scrubs sensitive headers from a response before saving.
     *
     * @param HttpResponse $response The original response.
     *
     * @return HttpResponse A response with sensitive headers redacted.
     */
    private function scrubResponse(HttpResponse $response): HttpResponse {
        $headers = $response->getHeaders();
        $changed = false;

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $this->scrubHeaders, true)) {
                $headers[$key] = '[REDACTED]';
                $changed = true;
            }
        }

        if (!$changed) {
            return $response;
        }

        return new HttpResponse($response->getStatusCode(), $headers, $response->getBody());
    }
}
