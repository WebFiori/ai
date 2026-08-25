<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool;

use WebFiori\Ai\Message;
use WebFiori\Ai\Role;

/**
 * A remember strategy that detects corrections and important facts using keyword patterns.
 *
 * This strategy scans the last user message for regex patterns that indicate
 * corrections, clarifications, or important facts the user wants remembered.
 * When a match is found, the entire user message is returned as the fact to store.
 *
 * Default patterns detect common correction indicators like "actually",
 * "correction:", "remember that", "important:", etc.
 *
 * @author Ibrahim
 */
class KeywordRememberStrategy implements RememberStrategyInterface {
    /**
     * Default regex patterns for detecting correction indicators.
     */
    private const DEFAULT_PATTERNS = [
        '/\bactually\b/i',
        '/correction:/i',
        '/no,?\s*it\'s/i',
        '/no,?\s*it is/i',
        '/\bwrong\b/i',
        '/\bincorrect\b/i',
        '/instead,?\s*use/i',
        '/the correct/i',
        '/update:/i',
        '/fix:/i',
        '/note:/i',
        '/remember that/i',
        '/keep in mind/i',
        '/important:/i',
        '/\bfyi\b/i',
    ];

    /**
     * Regex patterns used to detect correction indicators.
     *
     * @var string[]
     */
    private array $patterns;

    /**
     * Creates a new KeywordRememberStrategy instance.
     *
     * @param string[] $patterns Custom regex patterns to use. If empty, default
     *        patterns are used that match common correction indicators like
     *        "actually", "correction:", "remember that", etc.
     */
    public function __construct(array $patterns = []) {
        $this->patterns = !empty($patterns) ? $patterns : self::DEFAULT_PATTERNS;
    }

    /**
     * Extracts facts by matching keyword patterns in the last user message.
     *
     * Scans the last user message in the conversation for any of the configured
     * regex patterns. If a match is found, the full user message content is
     * returned as a single fact to be stored.
     *
     * @param Message[] $messages The conversation messages.
     * @param string $agentResponse The agent's/model's response (unused by this strategy).
     *
     * @return string[] A single-element array with the user message if a pattern
     *         matched, or an empty array otherwise.
     */
    public function extract(array $messages, string $agentResponse): array {
        $lastUserMessage = $this->findLastUserMessage($messages);

        if ($lastUserMessage === null) {
            return [];
        }

        $content = $lastUserMessage->getContent();

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return [$content];
            }
        }

        return [];
    }

    /**
     * Returns the regex patterns used for detection.
     *
     * @return string[] The patterns.
     */
    public function getPatterns(): array {
        return $this->patterns;
    }

    /**
     * Finds the last user message in the messages array.
     *
     * @param Message[] $messages The conversation messages.
     *
     * @return Message|null The last user message, or null if none found.
     */
    private function findLastUserMessage(array $messages): ?Message {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->getRole() === Role::USER->value) {
                return $messages[$i];
            }
        }

        return null;
    }
}
