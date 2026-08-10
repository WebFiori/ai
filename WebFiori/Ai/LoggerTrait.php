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

use WebFiori\Ai\Redaction\RedactionService;

/**
 * Provides logging capability via a user-supplied callback function.
 *
 * Classes that use this trait can emit log messages at standard levels
 * (debug, info, warning, error) without depending on any logging library.
 * If no callback is configured, logging calls are no-ops.
 *
 * If a RedactionService is configured, context data is redacted before
 * being passed to the callback.
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
     * The redaction service for sanitizing log context.
     *
     * @var RedactionService|null
     */
    private ?RedactionService $logRedactionService = null;

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
     * @param callable|null $callback The logging callback, or null to disable.
     */
    public function setLogCallback(?callable $callback): void {
        $this->logCallback = $callback;
    }

    /**
     * Sets the redaction service for log context sanitization.
     *
     * @param RedactionService|null $service The redaction service, or null to disable.
     */
    protected function setLogRedactionService(?RedactionService $service): void {
        $this->logRedactionService = $service;
    }

    /**
     * Emits a log message at the specified level.
     */
    private function log(string $level, string $message, array $context): void {
        if ($this->logCallback === null) {
            return;
        }

        if ($this->logRedactionService !== null) {
            $message = $this->logRedactionService->redactString($message);
            $context = $this->logRedactionService->redactContext($context);
        }

        ($this->logCallback)($level, $message, $context);
    }

    /**
     * Emits a debug-level log message.
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
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    protected function logError(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    /**
     * Emits an info-level log message.
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
     * @param string $message The log message.
     * @param array<string, mixed> $context Structured context data.
     */
    protected function logWarning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }
}
