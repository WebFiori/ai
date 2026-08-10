<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Redaction;

/**
 * Configuration for PII redaction in logs and metrics.
 *
 * Controls which data is redacted before reaching logging and metrics
 * callbacks. API keys and Bearer tokens are always redacted regardless
 * of configuration.
 *
 * @author Ibrahim
 */
class RedactionConfig {
    /**
     * Custom redaction rules to apply in addition to built-ins.
     *
     * @var RedactionRule[]
     */
    private array $customRules;

    /**
     * Names of built-in rules to disable (api_key and bearer_token cannot be disabled).
     *
     * @var string[]
     */
    private array $disabledRules;

    /**
     * Whether to redact request body content (user messages).
     *
     * @var bool
     */
    private bool $redactRequestBodies;

    /**
     * Whether to redact response body content (AI replies).
     *
     * @var bool
     */
    private bool $redactResponseBodies;

    /**
     * Creates a new RedactionConfig instance.
     *
     * @param bool $redactRequestBodies Whether to redact user message content. Default: false.
     * @param bool $redactResponseBodies Whether to redact AI response content. Default: false.
     * @param string[] $disabledRules Built-in rule names to disable (e.g., ['email', 'phone']).
     *        'api_key' and 'bearer_token' cannot be disabled.
     * @param RedactionRule[] $customRules Additional custom redaction rules.
     */
    public function __construct(
        bool $redactRequestBodies = false,
        bool $redactResponseBodies = false,
        array $disabledRules = [],
        array $customRules = []
    ) {
        $this->redactRequestBodies = $redactRequestBodies;
        $this->redactResponseBodies = $redactResponseBodies;
        // api_key and bearer_token can never be disabled
        $this->disabledRules = array_diff($disabledRules, ['api_key', 'bearer_token']);
        $this->customRules = $customRules;
    }

    /**
     * Returns the custom redaction rules.
     *
     * @return RedactionRule[] Custom rules.
     */
    public function getCustomRules(): array {
        return $this->customRules;
    }

    /**
     * Returns the names of disabled built-in rules.
     *
     * @return string[] Disabled rule names.
     */
    public function getDisabledRules(): array {
        return $this->disabledRules;
    }

    /**
     * Returns whether a built-in rule is enabled.
     *
     * @param string $name The rule name.
     *
     * @return bool True if the rule is active.
     */
    public function isRuleEnabled(string $name): bool {
        return !in_array($name, $this->disabledRules, true);
    }

    /**
     * Returns whether request body content should be redacted.
     *
     * @return bool True if request bodies are redacted.
     */
    public function isRedactRequestBodies(): bool {
        return $this->redactRequestBodies;
    }

    /**
     * Returns whether response body content should be redacted.
     *
     * @return bool True if response bodies are redacted.
     */
    public function isRedactResponseBodies(): bool {
        return $this->redactResponseBodies;
    }
}
