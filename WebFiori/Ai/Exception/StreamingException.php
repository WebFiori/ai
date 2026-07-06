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
 * Thrown when an error occurs during streaming response processing.
 *
 * This covers malformed SSE data, unexpected stream termination,
 * and other errors specific to streaming operations.
 *
 * @author Ibrahim
 */
class StreamingException extends AiException {
}
