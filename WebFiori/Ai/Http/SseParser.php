<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Http;

/**
 * Parses Server-Sent Events (SSE) streams.
 *
 * Handles buffering of partial chunks and emitting complete SSE events.
 * The SSE format is: lines prefixed with "data: " separated by double
 * newlines. A "[DONE]" signal indicates the stream has ended.
 *
 * @author Ibrahim
 */
class SseParser {
    /**
     * Buffer for incomplete data between chunks.
     *
     * @var string
     */
    private string $buffer = '';

    /**
     * Callback invoked when the stream signals completion ([DONE]).
     *
     * @var callable|null
     */
    private $onDone;

    /**
     * Callback invoked for each complete SSE data payload.
     *
     * @var callable
     */
    private $onEvent;

    /**
     * Creates a new SseParser instance.
     *
     * @param callable $onEvent Callback invoked for each complete SSE data payload.
     *        Signature: function(string $data): void
     *        The $data parameter contains the raw data field content (without
     *        the "data: " prefix), typically a JSON string.
     * @param callable|null $onDone Optional callback invoked when the [DONE]
     *        signal is received. Signature: function(): void
     */
    public function __construct(callable $onEvent, ?callable $onDone = null) {
        $this->onEvent = $onEvent;
        $this->onDone = $onDone;
    }

    /**
     * Feeds raw data from the HTTP stream into the parser.
     *
     * Call this method from the HTTP client's streaming callback. The parser
     * buffers partial data and emits complete events as they become available.
     *
     * @param string $chunk A chunk of raw data from the stream.
     */
    public function feed(string $chunk): void {
        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $event = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);

            $this->processEvent($event);
        }

        // Also handle \r\n\r\n line endings
        while (($pos = strpos($this->buffer, "\r\n\r\n")) !== false) {
            $event = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 4);

            $this->processEvent($event);
        }
    }

    /**
     * Returns any remaining buffered data.
     *
     * Useful for debugging or checking if the stream ended with incomplete data.
     *
     * @return string The remaining buffer content.
     */
    public function getBuffer(): string {
        return $this->buffer;
    }

    /**
     * Resets the parser state, clearing the buffer.
     */
    public function reset(): void {
        $this->buffer = '';
    }

    /**
     * Processes a single complete SSE event block.
     *
     * @param string $event The raw event block (may contain multiple lines).
     */
    private function processEvent(string $event): void {
        $lines = preg_split('/\r?\n/', $event);
        $data = '';

        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ')) {
                $data .= substr($line, 6);
            } else if ($line === 'data:') {
                // Empty data line
                $data .= '';
            }
            // Ignore other fields (event:, id:, retry:) for now
        }

        if ($data === '') {
            return;
        }

        // Check for [DONE] signal
        $trimmed = trim($data);

        if ($trimmed === '[DONE]') {
            if ($this->onDone !== null) {
                ($this->onDone)();
            }

            return;
        }

        ($this->onEvent)($data);
    }
}
