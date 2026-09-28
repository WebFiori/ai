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
 * For performance, all active patterns are compiled into arrays on first
 * use and applied in a single preg_replace() call instead of N separate calls.
 *
 * @author Ibrahim
 */
class RedactionService {
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
     * Compiled patterns array for single-pass redaction.
     * Built lazily on first use.
     *
     * @var string[]|null
     */
    private ?array $compiledPatterns = null;

    /**
     * Compiled replacements array matching compiledPatterns.
     *
     * @var string[]|null
     */
    private ?array $compiledReplacements = null;

    /**
     * The redaction configuration.
     *
     * @var RedactionConfig
     */
    private RedactionConfig $config;

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
     * Creates a new RedactionService instance.
     *
     * @param RedactionConfig $config The redaction configuration.
     */
    public function __construct(RedactionConfig $config) {
        $this->config = $config;
        $this->initRules();
    }

    /**
     * Detects sensitive data in a string without redacting it.
     *
     * Runs every active rule (see {@see getActiveRules()}) against the text and
     * returns a list of matches carrying the rule name, byte offsets, matched
     * value, and the replacement token that would be applied. Unlike
     * {@see redactString()}, this preserves match positions so consumers can
     * build their own findings.
     *
     * Semantics:
     * - Offsets are byte offsets; `start` is inclusive, `end` is exclusive.
     * - All matches from all rules are reported, including overlapping matches
     *   from different rules (detection does not suppress overlaps the way
     *   single-pass redaction does).
     * - Matches are sorted by start offset ascending; ties preserve active-rule
     *   order.
     *
     * @param string $text The text to scan.
     *
     * @return RedactionMatch[] The detected matches, sorted by start offset.
     */
    public function detect(string $text): array {
        if ($text === '') {
            return [];
        }

        $matches = [];

        foreach ($this->getActiveRules() as $order => $rule) {
            $found = [];

            if (preg_match_all($rule->getPattern(), $text, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($found as $set) {
                // Group 0 is the full match: [value, byteOffset].
                $value = $set[0][0];
                $start = $set[0][1];

                $matches[] = [
                    'order' => $order,
                    'match' => new RedactionMatch(
                        $rule->getName(),
                        $start,
                        $start + strlen($value),
                        $value,
                        $rule->getReplacement()
                    ),
                ];
            }
        }

        // Sort by start offset ascending; ties keep active-rule order (stable).
        usort($matches, function (array $a, array $b): int
        {
            return [$a['match']->getStart(), $a['order']] <=> [$b['match']->getStart(), $b['order']];
        });

        return array_map(static fn (array $m): RedactionMatch => $m['match'], $matches);
    }

    /**
     * Returns the currently active redaction rules.
     *
     * The active set is: mandatory rules (always) + optional built-in rules that
     * are enabled by the configuration + custom rules, in that order. This is the
     * exact set applied by {@see redactString()}, exposed so consumers can build
     * offset-based detections (see {@see detect()}) without re-declaring the
     * library's regex patterns.
     *
     * @return RedactionRule[] The active rules.
     */
    public function getActiveRules(): array {
        $rules = [];

        // Mandatory rules — always included.
        foreach ($this->mandatoryRules as $rule) {
            $rules[] = $rule;
        }

        // Optional rules — only if enabled.
        foreach ($this->optionalRules as $rule) {
            if ($this->config->isRuleEnabled($rule->getName())) {
                $rules[] = $rule;
            }
        }

        // Custom rules — always included.
        foreach ($this->config->getCustomRules() as $rule) {
            $rules[] = $rule;
        }

        return $rules;
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
     * Redacts sensitive data from a string.
     *
     * All active patterns are applied in a single preg_replace() call
     * using arrays, which is significantly faster than N separate calls.
     *
     * @param string $text The text to redact.
     *
     * @return string The redacted text.
     */
    public function redactString(string $text): string {
        if ($text === '') {
            return $text;
        }

        $this->ensureCompiled();

        return preg_replace($this->compiledPatterns, $this->compiledReplacements, $text) ?? $text;
    }

    /**
     * Builds the compiled patterns and replacements arrays from active rules.
     * Called lazily on first redactString() call.
     */
    private function ensureCompiled(): void {
        if ($this->compiledPatterns !== null) {
            return;
        }

        $patterns = [];
        $replacements = [];

        // Single source of truth: compile exactly the active rule set.
        foreach ($this->getActiveRules() as $rule) {
            $patterns[] = $rule->getPattern();
            $replacements[] = $rule->getReplacement();
        }

        $this->compiledPatterns = $patterns;
        $this->compiledReplacements = $replacements;
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
