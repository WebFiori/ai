<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Tests\Ai;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Http\SseParser;

/**
 * Unit tests for the SseParser class.
 *
 * @author Ibrahim
 */
class SseParserTest extends TestCase {
    /**
     * @test
     */
    public function testBufferedPartialChunks() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        // First chunk: incomplete event
        $parser->feed("data: {\"tok");
        $this->assertEmpty($events);

        // Second chunk: completes the event
        $parser->feed("en\":\"Hello\"}\n\n");
        $this->assertCount(1, $events);
        $this->assertEquals('{"token":"Hello"}', $events[0]);
    }

    /**
     * @test
     */
    public function testCarriageReturnLineFeed() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        $parser->feed("data: {\"token\":\"Hi\"}\r\n\r\ndata: {\"token\":\" there\"}\r\n\r\n");

        $this->assertCount(2, $events);
        $this->assertEquals('{"token":"Hi"}', $events[0]);
        $this->assertEquals('{"token":" there"}', $events[1]);
    }

    /**
     * @test
     */
    public function testDoneSignal() {
        $events = [];
        $doneReceived = false;

        $parser = new SseParser(
            function (string $data) use (&$events) {
                $events[] = $data;
            },
            function () use (&$doneReceived) {
                $doneReceived = true;
            }
        );

        $parser->feed("data: {\"token\":\"Hi\"}\n\ndata: [DONE]\n\n");

        $this->assertCount(1, $events);
        $this->assertTrue($doneReceived);
    }

    /**
     * @test
     */
    public function testDoneWithoutCallback() {
        $events = [];

        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        // Should not throw when no onDone callback is configured
        $parser->feed("data: {\"token\":\"Hi\"}\n\ndata: [DONE]\n\n");

        $this->assertCount(1, $events);
    }

    /**
     * @test
     */
    public function testEmptyDataLineIgnored() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        // Event with only a comment and no data should not fire callback
        $parser->feed(": this is a comment\n\n");
        $this->assertEmpty($events);
    }

    /**
     * @test
     */
    public function testGetBuffer() {
        $parser = new SseParser(function (string $data) {});

        $parser->feed("data: partial");
        $this->assertEquals("data: partial", $parser->getBuffer());

        $parser->feed("\n\n");
        $this->assertEquals("", $parser->getBuffer());
    }

    /**
     * @test
     */
    public function testMultipleEventsInOneChunk() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        $chunk = "data: {\"token\":\"Hello\"}\n\ndata: {\"token\":\" world\"}\n\ndata: {\"token\":\"!\"}\n\n";
        $parser->feed($chunk);

        $this->assertCount(3, $events);
        $this->assertEquals('{"token":"Hello"}', $events[0]);
        $this->assertEquals('{"token":" world"}', $events[1]);
        $this->assertEquals('{"token":"!"}', $events[2]);
    }

    /**
     * @test
     */
    public function testReset() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        $parser->feed("data: partial");
        $this->assertNotEmpty($parser->getBuffer());

        $parser->reset();
        $this->assertEquals('', $parser->getBuffer());
    }

    /**
     * @test
     */
    public function testSingleEvent() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        $parser->feed("data: {\"token\":\"Hello\"}\n\n");

        $this->assertCount(1, $events);
        $this->assertEquals('{"token":"Hello"}', $events[0]);
    }

    /**
     * @test
     */
    public function testSmallChunks() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        // Simulate byte-by-byte feeding
        $full = "data: {\"token\":\"X\"}\n\n";

        for ($i = 0; $i < strlen($full); $i++) {
            $parser->feed($full[$i]);
        }

        $this->assertCount(1, $events);
        $this->assertEquals('{"token":"X"}', $events[0]);
    }

    /**
     * @test
     */
    public function testStreamWithEventField() {
        $events = [];
        $parser = new SseParser(function (string $data) use (&$events) {
            $events[] = $data;
        });

        // Some providers include "event:" field — should be ignored, data extracted
        $parser->feed("event: message\ndata: {\"content\":\"hi\"}\n\n");

        $this->assertCount(1, $events);
        $this->assertEquals('{"content":"hi"}', $events[0]);
    }
}
