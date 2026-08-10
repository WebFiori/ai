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

/**
 * Configuration for structured audit logging.
 *
 * Controls whether request messages and AI response content are included
 * in audit entries. Content is always PII-redacted before being written.
 *
 * @author Ibrahim
 */
class AuditConfig {
    /**
     * Whether to include request messages in audit entries.
     *
     * @var bool
     */
    private bool $includeMessages;

    /**
     * Whether to include the AI response content in audit entries.
     *
     * @var bool
     */
    private bool $includeResponse;

    /**
     * Creates a new AuditConfig instance.
     *
     * @param bool $includeMessages Whether to include request messages. Default: false.
     * @param bool $includeResponse Whether to include AI response content. Default: false.
     */
    public function __construct(
        bool $includeMessages = false,
        bool $includeResponse = false
    ) {
        $this->includeMessages = $includeMessages;
        $this->includeResponse = $includeResponse;
    }

    /**
     * Returns whether request messages are included in audit entries.
     *
     * @return bool True if messages are included.
     */
    public function isIncludeMessages(): bool {
        return $this->includeMessages;
    }

    /**
     * Returns whether AI response content is included in audit entries.
     *
     * @return bool True if response content is included.
     */
    public function isIncludeResponse(): bool {
        return $this->includeResponse;
    }
}
