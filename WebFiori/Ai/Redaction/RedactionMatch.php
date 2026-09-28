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
 * A single detection produced by {@see RedactionService::detect()}.
 *
 * Unlike {@see RedactionService::redactString()}, which returns already-redacted
 * text, a detection preserves *where* a match occurred and *which rule* matched,
 * so consumers can build their own findings (e.g. `{type, start, end, rule,
 * severity}`) while reusing the service's built-in rules as the single source
 * of truth.
 *
 * Offsets are **byte** offsets into the scanned string (matching PCRE's native
 * `PREG_OFFSET_CAPTURE` behavior). `start` is inclusive and `end` is exclusive,
 * so the matched span is recoverable with `substr($text, $start, $end - $start)`.
 *
 * @author Ibrahim
 */
class RedactionMatch {
    /**
     * The exclusive end byte offset of the match.
     *
     * @var int
     */
    private int $end;

    /**
     * The replacement token the matching rule would apply.
     *
     * @var string
     */
    private string $replacement;

    /**
     * The name of the rule that matched.
     *
     * @var string
     */
    private string $rule;

    /**
     * The inclusive start byte offset of the match.
     *
     * @var int
     */
    private int $start;

    /**
     * The matched substring.
     *
     * @var string
     */
    private string $value;

    /**
     * Creates a new RedactionMatch instance.
     *
     * @param string $rule The name of the rule that matched.
     * @param int $start The inclusive start byte offset.
     * @param int $end The exclusive end byte offset.
     * @param string $value The matched substring.
     * @param string $replacement The replacement token the rule would apply.
     */
    public function __construct(string $rule, int $start, int $end, string $value, string $replacement) {
        $this->rule = $rule;
        $this->start = $start;
        $this->end = $end;
        $this->value = $value;
        $this->replacement = $replacement;
    }

    /**
     * Returns the exclusive end byte offset of the match.
     *
     * @return int The end offset.
     */
    public function getEnd(): int {
        return $this->end;
    }

    /**
     * Returns the replacement token the matching rule would apply.
     *
     * @return string The replacement token (e.g. '[EMAIL]').
     */
    public function getReplacement(): string {
        return $this->replacement;
    }

    /**
     * Returns the name of the rule that matched.
     *
     * @return string The rule name (e.g. 'email').
     */
    public function getRule(): string {
        return $this->rule;
    }

    /**
     * Returns the inclusive start byte offset of the match.
     *
     * @return int The start offset.
     */
    public function getStart(): int {
        return $this->start;
    }

    /**
     * Returns the matched substring.
     *
     * @return string The matched text.
     */
    public function getValue(): string {
        return $this->value;
    }

    /**
     * Exports the match to a JSON-safe associative array.
     *
     * @return array{rule: string, start: int, end: int, value: string, replacement: string}
     */
    public function toArray(): array {
        return [
            'rule' => $this->rule,
            'start' => $this->start,
            'end' => $this->end,
            'value' => $this->value,
            'replacement' => $this->replacement,
        ];
    }
}
