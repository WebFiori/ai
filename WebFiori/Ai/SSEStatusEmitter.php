<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai;

/**
 * Server-Sent Events (SSE) status emitter.
 *
 * Streams status events directly to the browser via SSE format.
 * Requires SSE headers to be set before use.
 *
 * ```php
 * header('Content-Type: text/event-stream');
 * header('Cache-Control: no-cache');
 * header('X-Accel-Buffering: no');
 *
 * $client->setStatusEmitter(new SSEStatusEmitter());
 * $response = $client->chat($messages, [
 *     'tools' => $tools,
 *     'auto_execute_tools' => true,
 * ]);
 *
 * // Send final response
 * echo "event: response\n";
 * echo "data: " . json_encode(['content' => $response->getMessage()->getContent()]) . "\n\n";
 * ```
 *
 * @author Ibrahim
 */
class SSEStatusEmitter implements StatusEmitterInterface {
    /**
     * Emits a status event as an SSE message.
     *
     * @param string $status The status identifier.
     * @param array<string, mixed> $context Additional context data.
     */
    public function emit(string $status, array $context = []): void {
        echo "event: status\n";
        echo 'data: '.json_encode(['status' => $status, ...$context])."\n\n";
        ob_flush();
        flush();
    }
}
