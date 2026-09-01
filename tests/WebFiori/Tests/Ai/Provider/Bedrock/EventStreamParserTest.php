<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai\Provider\Bedrock;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Provider\Bedrock\EventStreamParser;

/**
 * Logic tests for the AWS Event Stream binary framing parser.
 *
 * These validate the correctness of the framing logic — length prefixes,
 * TLV header parsing, cross-chunk buffering, and malformed-frame guards —
 * by building real binary frames with pack(). No network involved.
 */
class EventStreamParserTest extends TestCase {
    /**
     * Builds a single AWS Event Stream message frame.
     *
     * Layout: [4B total_len][4B headers_len][4B prelude_crc]
     *         [headers][payload][4B message_crc]
     *
     * Each header: [1B name_len][name][1B type=7][2B value_len][value]
     */
    private function frame(string $eventType, string $payload): string {
        // Build one ':event-type' string header (type 7).
        $name = ':event-type';
        $header = chr(strlen($name)).$name.chr(7).pack('n', strlen($eventType)).$eventType;

        $headersLen = strlen($header);
        // total = 12 (prelude) + headers + payload + 4 (message CRC)
        $totalLen = 12 + $headersLen + strlen($payload) + 4;

        return pack('N', $totalLen)      // total length
            .pack('N', $headersLen)      // headers length
            .pack('N', 0)                // prelude CRC (unused by parser)
            .$header
            .$payload
            .pack('N', 0);               // message CRC (unused by parser)
    }

    public function testParsesSingleEvent(): void {
        $events = [];
        $parser = new EventStreamParser(function (string $type, array $json) use (&$events): void {
            $events[] = [$type, $json];
        });

        $parser->feed($this->frame('contentBlockDelta', json_encode(['delta' => ['text' => 'Hi']])));

        $this->assertCount(1, $events);
        $this->assertSame('contentBlockDelta', $events[0][0]);
        $this->assertSame('Hi', $events[0][1]['delta']['text']);
    }

    public function testParsesMultipleEventsInOneChunk(): void {
        $events = [];
        $parser = new EventStreamParser(function (string $type, array $json) use (&$events): void {
            $events[] = $type;
        });

        $data = $this->frame('messageStart', json_encode(['a' => 1]))
            .$this->frame('contentBlockDelta', json_encode(['delta' => ['text' => 'x']]))
            .$this->frame('messageStop', json_encode(['b' => 2]));

        $parser->feed($data);

        $this->assertSame(['messageStart', 'contentBlockDelta', 'messageStop'], $events);
    }

    public function testBuffersEventSplitAcrossChunks(): void {
        $events = [];
        $parser = new EventStreamParser(function (string $type, array $json) use (&$events): void {
            $events[] = $type;
        });

        $frame = $this->frame('contentBlockDelta', json_encode(['delta' => ['text' => 'chunked']]));

        // Feed the frame in two pieces: the parser must buffer until complete.
        $parser->feed(substr($frame, 0, 7));
        $this->assertCount(0, $events, 'No event should fire until the full frame arrives');

        $parser->feed(substr($frame, 7));
        $this->assertSame(['contentBlockDelta'], $events);
    }

    public function testIncompletePreludeIsBuffered(): void {
        $events = [];
        $parser = new EventStreamParser(function (string $type, array $json) use (&$events): void {
            $events[] = $type;
        });

        // Fewer than 12 bytes — cannot even read the prelude yet.
        $parser->feed("\x00\x00");
        $this->assertCount(0, $events);
    }

    public function testMalformedJsonPayloadIsSkipped(): void {
        $events = [];
        $parser = new EventStreamParser(function (string $type, array $json) use (&$events): void {
            $events[] = $type;
        });

        // Valid framing but the payload is not valid JSON -> event dropped.
        $parser->feed($this->frame('contentBlockDelta', 'not-json{'));

        $this->assertCount(0, $events);
    }

    public function testEmptyPayloadFrameIsSkipped(): void {
        $events = [];
        $parser = new EventStreamParser(function (string $type, array $json) use (&$events): void {
            $events[] = $type;
        });

        // A frame whose payload length computes to <= 0 is ignored.
        $parser->feed($this->frame('messageStop', ''));

        $this->assertCount(0, $events);
    }
}
