<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Bedrock;

/**
 * Parses AWS Event Stream binary framing used by Bedrock streaming responses.
 *
 * AWS Event Stream format per message:
 * - 4 bytes: total length (big-endian int)
 * - 4 bytes: headers length (big-endian int)
 * - 4 bytes: prelude CRC
 * - N bytes: headers (type-length-value)
 * - M bytes: payload (JSON)
 * - 4 bytes: message CRC
 *
 * Each event has headers including ':event-type' which identifies the
 * message type (messageStart, contentBlockDelta, messageStop, metadata, etc.).
 *
 * @author Ibrahim
 */
class EventStreamParser {
    /**
     * Incomplete data buffer between chunks.
     *
     * @var string
     */
    private string $buffer = '';

    /**
     * Callback invoked for each fully parsed event.
     *
     * @var callable
     */
    private $onEvent;

    /**
     * Creates a new EventStreamParser.
     *
     * @param callable $onEvent Callback invoked for each event.
     *        Signature: function(string $eventType, array $payload): void
     */
    public function __construct(callable $onEvent) {
        $this->onEvent = $onEvent;
    }

    /**
     * Feeds raw binary data into the parser.
     *
     * Call this from the HTTP client streaming callback.
     *
     * @param string $chunk A chunk of raw binary data from the stream.
     */
    public function feed(string $chunk): void {
        $this->buffer .= $chunk;

        while ($this->canReadMessage()) {
            $this->readMessage();
        }
    }

    /**
     * Checks if there is enough data in the buffer to read a complete message.
     *
     * @return bool True if a complete message can be read.
     */
    private function canReadMessage(): bool {
        // Need at least 12 bytes for prelude (total_len + headers_len + prelude_crc)
        if (strlen($this->buffer) < 12) {
            return false;
        }

        $totalLength = unpack('N', substr($this->buffer, 0, 4))[1];

        return strlen($this->buffer) >= $totalLength;
    }

    /**
     * Parses AWS Event Stream header bytes into a key-value array.
     *
     * Header format (repeated):
     * - 1 byte: name length
     * - N bytes: header name
     * - 1 byte: value type (7 = string)
     * - 2 bytes: value length
     * - N bytes: value
     *
     * @param string $data Raw header bytes.
     *
     * @return array<string, string> Parsed headers.
     */
    private function parseHeaders(string $data): array {
        $headers = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            // Read header name
            if ($offset >= $length) {
                break;
            }

            $nameLen = ord($data[$offset]);
            $offset++;

            if ($offset + $nameLen > $length) {
                break;
            }

            $name = substr($data, $offset, $nameLen);
            $offset += $nameLen;

            // Read value type (1 byte)
            if ($offset >= $length) {
                break;
            }

            $valueType = ord($data[$offset]);
            $offset++;

            // Only handle string type (7)
            if ($valueType === 7) {
                if ($offset + 2 > $length) {
                    break;
                }

                $valueLen = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;

                if ($offset + $valueLen > $length) {
                    break;
                }

                $value = substr($data, $offset, $valueLen);
                $offset += $valueLen;

                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Reads and processes one complete message from the buffer.
     */
    private function readMessage(): void {
        $totalLength = unpack('N', substr($this->buffer, 0, 4))[1];
        $headersLength = unpack('N', substr($this->buffer, 4, 4))[1];

        // Extract the full message and advance buffer
        $message = substr($this->buffer, 0, $totalLength);
        $this->buffer = substr($this->buffer, $totalLength);

        // Parse headers (starts at byte 12, after prelude)
        $headersData = substr($message, 12, $headersLength);
        $headers = $this->parseHeaders($headersData);

        // Parse payload (after headers, before final 4-byte CRC)
        $payloadOffset = 12 + $headersLength;
        $payloadLength = $totalLength - $payloadOffset - 4;

        if ($payloadLength <= 0) {
            return;
        }

        $payload = substr($message, $payloadOffset, $payloadLength);
        $eventType = $headers[':event-type'] ?? '';

        $json = json_decode($payload, true);

        if ($json === null) {
            return;
        }

        ($this->onEvent)($eventType, $json);
    }
}
