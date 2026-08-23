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

use WebFiori\Ai\Http\HttpResponse;

/**
 * An immutable value object representing one recorded HTTP exchange.
 *
 * Contains the request fingerprint, the recorded response (or streaming
 * chunks), metadata, and an optional human-readable name.
 *
 * Fixture files use this structure:
 * - Non-streaming: {"streaming": false, "response": {"status": 200, ...}}
 * - Streaming:     {"streaming": true,  "chunks": ["data: ...", ...]}
 *
 * @author Ibrahim
 */
class HttpFixture {
    /**
     * The SSE chunks for streaming fixtures.
     *
     * @var string[]
     */
    private array $chunks;

    /**
     * The fingerprint uniquely identifying the request.
     *
     * @var string
     */
    private string $fingerprint;

    /**
     * Optional human-readable name.
     *
     * @var string
     */
    private string $name;

    /**
     * Timestamp when the fixture was recorded.
     *
     * @var string
     */
    private string $recordedAt;

    /**
     * The recorded HTTP response for non-streaming fixtures.
     *
     * @var HttpResponse|null
     */
    private ?HttpResponse $response;

    /**
     * Whether this is a streaming fixture.
     *
     * @var bool
     */
    private bool $streaming;

    /**
     * Creates a new non-streaming HttpFixture.
     *
     * @param string $fingerprint The request fingerprint.
     * @param HttpResponse $response The recorded response.
     * @param string $name Optional human-readable name.
     * @param string|null $recordedAt ISO 8601 timestamp.
     */
    public function __construct(
        string $fingerprint,
        HttpResponse $response,
        string $name = '',
        ?string $recordedAt = null
    ) {
        $this->fingerprint = $fingerprint;
        $this->response = $response;
        $this->chunks = [];
        $this->streaming = false;
        $this->name = $name;
        $this->recordedAt = $recordedAt ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    /**
     * Deserializes a fixture from its JSON array representation.
     *
     * @param array<string, mixed> $data The decoded JSON data.
     *
     * @return self The deserialized fixture.
     *
     * @throws \InvalidArgumentException If required fields are missing.
     */
    public static function fromArray(array $data): self {
        $fingerprint = $data['fingerprint'] ?? null;

        if ($fingerprint === null) {
            throw new \InvalidArgumentException('Fixture is missing required field: fingerprint');
        }

        $name = $data['name'] ?? '';
        $recordedAt = $data['recorded_at'] ?? null;
        $streaming = (bool) ($data['streaming'] ?? false);

        if ($streaming) {
            $chunks = $data['chunks'] ?? [];

            return self::streaming($fingerprint, $chunks, $name, $recordedAt);
        }

        $responseData = $data['response'] ?? [];
        $status = (int) ($responseData['status'] ?? 200);
        $headers = $responseData['headers'] ?? [];
        $body = $responseData['body'] ?? '';

        if (is_array($body)) {
            $body = json_encode($body, \JSON_UNESCAPED_UNICODE);
        }

        return new self(
            $fingerprint,
            new HttpResponse($status, $headers, $body),
            $name,
            $recordedAt
        );
    }

    /**
     * Returns the SSE chunks for streaming fixtures.
     *
     * @return string[] The chunks, or empty array for non-streaming.
     */
    public function getChunks(): array {
        return $this->chunks;
    }

    /**
     * Returns the fingerprint.
     *
     * @return string
     */
    public function getFingerprint(): string {
        return $this->fingerprint;
    }

    /**
     * Returns the human-readable name.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Returns the timestamp when the fixture was recorded.
     *
     * @return string ISO 8601 timestamp.
     */
    public function getRecordedAt(): string {
        return $this->recordedAt;
    }

    /**
     * Returns the recorded HTTP response (non-streaming only).
     *
     * @return HttpResponse|null Null for streaming fixtures.
     */
    public function getResponse(): ?HttpResponse {
        return $this->response;
    }

    /**
     * Returns whether this is a streaming fixture.
     *
     * @return bool
     */
    public function isStreaming(): bool {
        return $this->streaming;
    }

    /**
     * Creates a streaming HttpFixture from SSE chunks.
     *
     * @param string $fingerprint The request fingerprint.
     * @param string[] $chunks The raw SSE chunks.
     * @param string $name Optional human-readable name.
     * @param string|null $recordedAt ISO 8601 timestamp.
     *
     * @return self The streaming fixture.
     */
    public static function streaming(
        string $fingerprint,
        array $chunks,
        string $name = '',
        ?string $recordedAt = null
    ): self {
        $fixture = new self(
            $fingerprint,
            new HttpResponse(200, [], ''),
            $name,
            $recordedAt
        );
        $fixture->streaming = true;
        $fixture->chunks = $chunks;
        $fixture->response = null;

        return $fixture;
    }

    /**
     * Serializes the fixture to its JSON array representation.
     *
     * @return array<string, mixed> The serializable data.
     */
    public function toArray(): array {
        $data = [
            'name' => $this->name,
            'fingerprint' => $this->fingerprint,
            'recorded_at' => $this->recordedAt,
            'streaming' => $this->streaming,
        ];

        if ($this->streaming) {
            $data['chunks'] = $this->chunks;
        } else {
            $responseBody = $this->response->getBody();
            $decoded = json_decode($responseBody, true);

            $data['response'] = [
                'status' => $this->response->getStatusCode(),
                'headers' => $this->response->getHeaders(),
                'body' => $decoded !== null ? $decoded : $responseBody,
            ];
        }

        return $data;
    }
}
