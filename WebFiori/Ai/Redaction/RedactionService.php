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
 * Applies PII redaction to strings and log/metric context arrays.
 *
 * API keys and Bearer tokens are always redacted. Additional rules
 * are applied based on the provided configuration.
 *
 * @author Ibrahim
 */
class RedactionService {
    /**
     * Mandatory rules that are always applied regardless of config.
     *
     * @var RedactionRule[]
     */
    private array $mandatoryRules;

    /**
     * Optional built-in rules that can be disabled per config.
     *
     * @var RedactionRule[]
     */
    private array $optionalRules;

    /**
     * The redaction configuration.
     *
     * @var RedactionConfig
     */
    private RedactionConfig $config;

    /**
     * Log/metric context keys that may contain sensitive body content.
     *
     * @var string[]
     */
    private const REQUEST_BODY_KEYS = ['body', 'request_body', 'messages', 'content', 'prompt'];

    /**
     * Log/metric context keys that may contain sensitive response content.
     *
     * @var string[]
     */
    private const RESPONSE_BODY_KEYS = ['response_body', 'response', 'content', 'text'];

    /**
     * Creates a new RedactionService instance.
     *
     * @param RedactionConfig $config The redaction configuration.
     */
    public function __construct(RedactionConfig $config) {
        $this->config = $config;
        $this->initRules();
    }

    /**
     * Redacts sensitive data from a string.
     *
     * Always applies mandatory rules (API keys, Bearer tokens).
     * Applies optional built-in and custom rules based on configuration.
     *
     * @param string $text The text to redact.
     *
     * @return string The redacted text.
     */
    public function redactString(string $text): string {
        if ($text === '') {
            return $text;
        }

        // Always apply mandatory rules
        foreach ($this->mandatoryRules as $rule) {
            $text = preg_replace($rule->getPattern(), $rule->getReplacement(), $text) ?? $text;
        }

        // Apply optional built-in rules if not disabled
        foreach ($this->optionalRules as $rule) {
            if ($this->config->isRuleEnabled($rule->getName())) {
                $text = preg_replace($rule->getPattern(), $rule->getReplacement(), $text) ?? $text;
            }
        }

        // Apply custom rules
        foreach ($this->config->getCustomRules() as $rule) {
            $text = preg_replace($rule->getPattern(), $rule->getReplacement(), $text) ?? $text;
        }

        return $text;
    }

    /**
     * Redacts sensitive data from a log/metric context array.
     *
     * Recursively processes string values. Applies body redaction to
     * known sensitive keys based on configuration.
     *
     * @param array<string, mixed> $context The context array to redact.
     *
     * @return array<string, mixed> The redacted context array.
     */
    public function redactContext(array $context): array {
        $result = [];

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->redactContextValue($key, $value);
            } elseif (is_array($value)) {
                $result[$key] = $this->redactContext($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Initializes the built-in redaction rules.
     */
    private function initRules(): void {
        // Mandatory — always applied, cannot be disabled
        $this->mandatoryRules = [
            new RedactionRule(
                'api_key',
                '/\b(sk-[a-zA-Z0-9]{20,}|AIza[a-zA-Z0-9_-]{35}|AKIA[A-Z0-9]{16})\b/',
                '[API_KEY]'
            ),
            new RedactionRule(
                'bearer_token',
                '/Bearer\s+[a-zA-Z0-9\-._~+\/]+=*/i',
                'Bearer [TOKEN]'
            ),
        ];

        // Optional — can be disabled via RedactionConfig
        $this->optionalRules = [
            new RedactionRule(
                'email',
                '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
                '[EMAIL]'
            ),
            // Saudi National ID — PDPL (10 digits starting with 1=citizen or 2=resident)
            // Must come before phone rule to avoid partial matching
            new RedactionRule(
                'saudi_id',
                '/(?<!\d)[12]\d{9}(?!\d)/',
                '[NATIONAL_ID]'
            ),
            new RedactionRule(
                'phone',
                '/\b(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
                '[PHONE]'
            ),
            new RedactionRule(
                'credit_card',
                '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|3(?:0[0-5]|[68][0-9])[0-9]{11}|6(?:011|5[0-9]{2})[0-9]{12})\b/',
                '[CC]'
            ),
            // US Social Security Number — HIPAA
            new RedactionRule(
                'ssn',
                '/\b(?!000|666|9\d{2})\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/',
                '[SSN]'
            ),
            // IPv4 addresses — GDPR, PDPL
            new RedactionRule(
                'ipv4',
                '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/',
                '[IP]'
            ),
            // IPv6 addresses — GDPR, PDPL
            new RedactionRule(
                'ipv6',
                '/\b(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}\b/',
                '[IP]'
            ),
            // IBAN — GDPR, PDPL (Saudi IBANs start with SA; generic covers SA + others)
            new RedactionRule(
                'iban',
                '/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}(?:[A-Z0-9]?){0,16}\b/',
                '[IBAN]'
            ),
        ];
    }

    /**
     * Redacts a single context value based on the key name.
     *
     * @param string $key The context key.
     * @param string $value The value to potentially redact.
     *
     * @return string The redacted value.
     */
    private function redactContextValue(string $key, string $value): string {
        $isRequestKey = in_array($key, self::REQUEST_BODY_KEYS, true);
        $isResponseKey = in_array($key, self::RESPONSE_BODY_KEYS, true);

        // If body redaction enabled, fully mask body content
        if ($isRequestKey && $this->config->isRedactRequestBodies()) {
            return '[REDACTED]';
        }

        if ($isResponseKey && $this->config->isRedactResponseBodies()) {
            return '[REDACTED]';
        }

        // Always apply pattern-based redaction to string values
        return $this->redactString($value);
    }
}
