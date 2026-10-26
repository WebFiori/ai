<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Exception;

/**
 * Thrown when an operation is cancelled via a CancellationToken.
 *
 * This is distinct from a transport {@see HttpException}: it signals a
 * deliberate, caller-initiated cancellation (e.g. a "stop" button or a detected
 * client disconnect), not a network or provider failure. Non-streaming chat
 * calls and the auto-execute tool loop surface cancellation by throwing this
 * exception; streaming calls instead invoke their `onCancel` callback with the
 * partial response.
 *
 * @author Ibrahim
 */
class OperationCancelledException extends AiException {
    /**
     * Creates a new OperationCancelledException instance.
     *
     * @param string $message A description of the cancellation.
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(string $message = 'The operation was cancelled.', ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}
