<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Routing\Strategy;

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Role;
use WebFiori\Ai\Routing\RoutingStrategyInterface;

/**
 * Routes based on a combined complexity score from multiple signals.
 *
 * Signals that increase complexity score:
 * - Long message content (above length threshold)
 * - Multiple tools available (suggests complex agentic task)
 * - Complexity keywords present (analyze, compare, summarize, generate report)
 * - File attachments (multi-modal, needs more reasoning)
 * - Long conversation history (context management)
 *
 * ```php
 * $router->setStrategy(new TaskComplexityStrategy(
 *     fastTier: 'fast',
 *     complexTier: 'smart',
 *     scoreThreshold: 2,  // score >= 2 → complex tier
 * ));
 * ```
 *
 * @author Ibrahim
 */
class TaskComplexityStrategy implements RoutingStrategyInterface {
    /**
     * Default complexity keywords.
     *
     * @var string[]
     */
    private const COMPLEXITY_KEYWORDS = [
        'analyze', 'analyse', 'compare', 'summarize', 'summarise',
        'across all', 'generate report', 'in depth', 'comprehensive',
        'step by step', 'explain in detail', 'break down',
    ];

    /**
     * @var string
     */
    private string $complexTier;

    /**
     * @var string
     */
    private string $fastTier;

    /**
     * @var string[]
     */
    private array $keywords;

    /**
     * @var int
     */
    private int $scoreThreshold;

    /**
     * Creates a new TaskComplexityStrategy instance.
     *
     * @param string $fastTier Tier for simple requests.
     * @param string $complexTier Tier for complex requests.
     * @param int $scoreThreshold Score required to route to complex tier. Default is 2.
     * @param string[]|null $keywords Custom complexity keywords, or null for defaults.
     */
    public function __construct(
        string $fastTier,
        string $complexTier,
        int $scoreThreshold = 2,
        ?array $keywords = null
    ) {
        $this->fastTier = $fastTier;
        $this->complexTier = $complexTier;
        $this->scoreThreshold = max(1, $scoreThreshold);
        $this->keywords = $keywords ?? self::COMPLEXITY_KEYWORDS;
    }

    /**
     * Returns the complex tier name.
     *
     * @return string
     */
    public function getComplexTier(): string {
        return $this->complexTier;
    }

    /**
     * Returns the fast tier name.
     *
     * @return string
     */
    public function getFastTier(): string {
        return $this->fastTier;
    }

    /**
     * Returns the keywords used for complexity detection.
     *
     * @return string[]
     */
    public function getKeywords(): array {
        return $this->keywords;
    }

    /**
     * Returns the score threshold.
     *
     * @return int
     */
    public function getScoreThreshold(): int {
        return $this->scoreThreshold;
    }

    /**
     * {@inheritdoc}
     */
    public function route(array $messages, array $options): ?string {
        $score = 0;

        $text = '';
        $userMessageCount = 0;
        $hasAttachments = false;

        foreach ($messages as $message) {
            if ($message->getRole() === Role::USER->value) {
                $text .= ' '.$message->getContent();
                $userMessageCount++;

                if ($message->isMultiModal()) {
                    $hasAttachments = true;
                }
            }
        }

        // Signal 1: Long message content (> 300 chars)
        if (strlen(trim($text)) > 300) {
            $score++;
        }

        // Signal 2: Complexity keywords
        $lowerText = strtolower($text);

        foreach ($this->keywords as $keyword) {
            if (str_contains($lowerText, strtolower($keyword))) {
                $score++;

                break; // count keyword signal once
            }
        }

        // Signal 3: Multiple tools (agentic task)
        $toolCount = count($options[ChatOption::TOOLS] ?? []);

        if ($toolCount >= 3) {
            $score++;
        }

        // Signal 4: File attachments
        if ($hasAttachments) {
            $score++;
        }

        // Signal 5: Long conversation (> 4 user messages)
        if ($userMessageCount > 4) {
            $score++;
        }

        return $score >= $this->scoreThreshold ? $this->complexTier : $this->fastTier;
    }
}
