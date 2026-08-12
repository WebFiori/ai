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
 * Defines a named redaction rule with a regex pattern and replacement.
 *
 * @author Ibrahim
 */
class RedactionRule {
    /**
     * The rule name (used to identify and optionally disable built-in rules).
     *
     * @var string
     */
    private string $name;

    /**
     * The regex pattern to match sensitive data.
     *
     * @var string
     */
    private string $pattern;

    /**
     * The replacement string for matched content.
     *
     * @var string
     */
    private string $replacement;

    /**
     * Creates a new RedactionRule instance.
     *
     * @param string $name Unique rule name (e.g., 'email', 'ssn').
     * @param string $pattern PCRE regex pattern to match sensitive data.
     * @param string $replacement Replacement string (e.g., '[EMAIL]').
     */
    public function __construct(string $name, string $pattern, string $replacement) {
        $this->name = $name;
        $this->pattern = $pattern;
        $this->replacement = $replacement;
    }

    /**
     * Returns the rule name.
     *
     * @return string The rule identifier.
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Returns the regex pattern.
     *
     * @return string The PCRE pattern.
     */
    public function getPattern(): string {
        return $this->pattern;
    }

    /**
     * Returns the replacement string.
     *
     * @return string The replacement text.
     */
    public function getReplacement(): string {
        return $this->replacement;
    }
}
