<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Audit;

use WebFiori\Ai\Message;
use WebFiori\Ai\Redaction\RedactionService;

/**
 * Provides structured audit logging for AI provider operations.
 *
 * Emits a complete audit entry for every AI operation via a developer-supplied
 * callback. Entries are always PII-redacted before reaching the callback.
 *
 * @author Ibrahim
 */
trait AuditTrait {
    /**
     * The audit callback.
     *
     * @var callable|null
     */
    private $auditCallback = null;

    /**
     * The audit configuration.
     *
     * @var AuditConfig
     */
    private AuditConfig $auditConfig;

    /**
     * Static audit context merged into every entry.
     *
     * @var array<string, mixed>
     */
    private array $auditContext = [];

    /**
     * Redaction service for sanitizing audit entries.
     *
     * @var RedactionService|null
     */
    private ?RedactionService $auditRedactionService = null;

    /**
     * Returns the current audit callback.
     *
     * @return callable|null The callback, or null if not set.
     */
    public function getAuditCallback(): ?callable {
        return $this->auditCallback;
    }

    /**
     * Returns the current audit configuration.
     *
     * @return AuditConfig The audit configuration.
     */
    public function getAuditConfig(): AuditConfig {
        return $this->auditConfig;
    }

    /**
     * Returns the static audit context.
     *
     * @return array<string, mixed> The audit context.
     */
    public function getAuditContext(): array {
        return $this->auditContext;
    }

    /**
     * Sets the callback for structured audit log entries.
     *
     * The callback receives a structured array for every AI operation.
     * Always called regardless of log levels. PII-redacted before invocation.
     *
     * ```php
     * $provider->setAuditCallback(function (array $entry) {
     *     DB::table('audit_log')->insert($entry);
     * });
     * ```
     *
     * @param callable|null $callback The audit callback, or null to disable.
     */
    public function setAuditCallback(?callable $callback): void {
        $this->auditCallback = $callback;
    }

    /**
     * Sets the audit configuration.
     *
     * Controls whether request messages and AI response content are included
     * in audit entries.
     *
     * @param AuditConfig $config The audit configuration.
     */
    public function setAuditConfig(AuditConfig $config): void {
        $this->auditConfig = $config;
    }

    /**
     * Sets the static audit context included in every entry.
     *
     * Merged with per-request context provided via options.
     *
     * ```php
     * $provider->setAuditContext([
     *     'tenant_id' => 'tenant-456',
     *     'feature'   => 'support-chatbot',
     * ]);
     * ```
     *
     * @param array<string, mixed> $context The static context.
     */
    public function setAuditContext(array $context): void {
        $this->auditContext = $context;
    }

    /**
     * Builds the base fields shared by all audit entries.
     *
     * @param string $requestId The unique request identifier.
     * @param string $operation The operation name.
     * @param string $provider The provider name.
     * @param string|null $model The model name.
     * @param array<string, mixed> $perRequestContext Per-request audit context.
     *
     * @return array<string, mixed> Base audit entry fields.
     */
    protected function buildBaseAuditEntry(
        string $requestId,
        string $operation,
        string $provider,
        ?string $model,
        array $perRequestContext = []
    ): array {
        return [
            'request_id' => $requestId,
            'timestamp' => (int) (microtime(true) * 1000),
            'operation' => $operation,
            'provider' => $provider,
            'model' => $model,
            'metadata' => array_merge($this->auditContext, $perRequestContext),
        ];
    }

    /**
     * Emits a structured audit entry.
     *
     * @param array<string, mixed> $entry The audit entry to emit.
     */
    protected function emitAudit(array $entry): void {
        if ($this->auditCallback === null) {
            return;
        }

        if ($this->auditRedactionService !== null) {
            $entry = $this->auditRedactionService->redactContext($entry);
        }

        ($this->auditCallback)($entry);
    }

    /**
     * Initializes the audit trait default values.
     *
     * Must be called from the class constructor.
     */
    protected function initAuditTrait(): void {
        $this->auditConfig = new AuditConfig();
        $this->auditContext = [];
    }

    /**
     * Serializes messages for inclusion in an audit entry.
     *
     * @param Message[] $messages The messages to serialize.
     *
     * @return array<int, array{role: string, content: string}> Serialized messages.
     */
    protected function serializeMessagesForAudit(array $messages): array {
        return array_map(fn (Message $m) => [
            'role' => $m->getRole(),
            'content' => $m->getContent(),
        ], $messages);
    }

    /**
     * Sets the redaction service for sanitizing audit entries.
     *
     * @param RedactionService|null $service The redaction service.
     */
    protected function setAuditRedactionService(?RedactionService $service): void {
        $this->auditRedactionService = $service;
    }
}
