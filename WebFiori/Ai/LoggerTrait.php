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
 * Provides logging capability via a user-supplied callback function.
 *
 * Classes that use this trait can emit log messages at standard levels
 * (debug, info, warning, error) without depending on any logging library.
 * If no callback is configured, logging calls are no-ops.
 *
 * Usage:
 * ```php
 * $provider->setLogCallback(function (string $level, string $message, array $context) {
 *     // Use any logger: PSR-3, Monolog, error_log, etc.
 * });
 * ```
 *
 * @author Ibrahim
 */
trait LoggerTrait {
    /**
     * The logging callback function.
     *
     * @var callable|null
     */
    private $logCallback = null;

    /**
     * Returns the currently configured log callback.
     *
     * @return callable|null The log callback, or null if not configured.
     */
    public function getLogCallback(): ?callable {
        return $this->logCallback;
    }

    /**
     * Sets a callback function for logging.
     *
     * The callback receives log entries with level, message, and context.
     * If no callback is set, no logging occurs.
     *
     * @param callable|null $callback The logging callback with signature:
     *        function(string $level, string $message, array $context): void
     *        Pass null to disable logging.
     */
    public function setLogCallback(?callable $callback): void {
        $this->logCallback = $callback;
    }

    /**
     * Emits a debug-level log message.
     *
     * Use for detailed diagnostic information such as full request/response
     * bodies when enabled.
     *
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    protected function logDebug(string $message, array $context = []): void {
        $this->log('debug', $message, $context);
    }

    /**
     * Emits an error-level log message.
     *
     * Use for API errors, connection failures, and other critical issues.
     *
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    protected function logError(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    /**
     * Emits an info-level log message.
     *
     * Use for general operational information such as request method,
     * URL, model, and token usage.
     *
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    protected function logInfo(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    /**
     * Emits a warning-level log message.
     *
     * Use for non-critical issues such as approaching rate limits
     * or retry attempts.
     *
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    protected function logWarning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }

    /**
     * Emits a log message at the specified level.
     *
     * Does nothing if no log callback is configured.
     *
     * @param string $level The log level ('debug', 'info', 'warning', 'error').
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    private function log(string $level, string $message, array $context): void {
        if ($this->logCallback !== null) {
            ($this->logCallback)($level, $message, $context);
        }
    }
}
