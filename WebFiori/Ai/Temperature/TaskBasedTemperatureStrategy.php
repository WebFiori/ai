<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Temperature;

use InvalidArgumentException;

/**
 * A temperature strategy that selects temperature based on task type keywords.
 *
 * Analyzes the last user message for keyword matches against configurable
 * buckets. Each bucket maps a set of keywords to a temperature value.
 * Structural signals from request options (JSON mode, tools) can further
 * cap the temperature.
 *
 * Buckets are represented as an array of arrays, where each entry has the
 * format: ['temperature' => float, 'keywords' => string[]]. This avoids
 * PHP's limitation of truncating float array keys to integers.
 *
 * @author Ibrahim
 */
class TaskBasedTemperatureStrategy implements TemperatureStrategyInterface {
    /**
     * The keyword buckets.
     *
     * Each entry: ['temperature' => float, 'keywords' => string[]]
     *
     * @var array<int, array{temperature: float, keywords: string[]}>
     */
    private array $buckets;

    /**
     * The default temperature when no keyword matches.
     *
     * @var float
     */
    private float $default;

    /**
     * Creates a new TaskBasedTemperatureStrategy instance.
     *
     * @param array<int, array{temperature: float, keywords: string[]}> $buckets
     *        Array of buckets, each with 'temperature' and 'keywords' keys.
     *        Defaults to a comprehensive set of task-type buckets.
     * @param float $default The default temperature when no keywords match (0.0 to 2.0).
     *
     * @throws InvalidArgumentException If any bucket temperature or default is outside 0.0–2.0.
     */
    public function __construct(array $buckets = [], float $default = 0.7) {
        if ($default < 0.0 || $default > 2.0) {
            throw new InvalidArgumentException(
                'Default temperature must be between 0.0 and 2.0, got ' . $default
            );
        }

        if (empty($buckets)) {
            $buckets = self::getDefaultBuckets();
        }

        foreach ($buckets as $bucket) {
            $temp = (float) $bucket['temperature'];

            if ($temp < 0.0 || $temp > 2.0) {
                throw new InvalidArgumentException(
                    'Bucket temperature must be between 0.0 and 2.0, got ' . $temp
                );
            }
        }

        $this->buckets = $buckets;
        $this->default = $default;
    }

    /**
     * Returns the keyword buckets.
     *
     * @return array<int, array{temperature: float, keywords: string[]}> The buckets.
     */
    public function getBuckets(): array {
        return $this->buckets;
    }

    /**
     * Returns the default temperature.
     *
     * @return float The default temperature value.
     */
    public function getDefault(): float {
        return $this->default;
    }

    /**
     * Determines temperature based on task type keywords and structural signals.
     *
     * Scans the last user message for keyword matches against the configured
     * buckets (sorted by temperature ascending). The first keyword match
     * determines the base temperature. Structural signals from options may
     * further cap the result.
     *
     * @param ChatContext $context The chat context containing messages and options.
     *
     * @return float The determined temperature value.
     */
    public function temperature(ChatContext $context): float {
        $messages = $context->getMessages();
        $options = $context->getOptions();

        // Extract the last user message content
        $content = $this->extractLastUserContent($messages);

        if ($content === null) {
            return $this->default;
        }

        $content = strtolower($content);

        // Determine base temperature from keyword matching
        $baseTemperature = $this->matchKeywords($content);

        // Apply structural signal caps
        $cap = $this->determineCap($options);

        if ($cap !== null) {
            return min($baseTemperature, $cap);
        }

        return $baseTemperature;
    }

    /**
     * Returns the default keyword buckets.
     *
     * @return array<int, array{temperature: float, keywords: string[]}> The default buckets.
     */
    private static function getDefaultBuckets(): array {
        return [
            [
                'temperature' => 0.2,
                'keywords' => [
                    'code', 'implement', 'function', 'class', 'method', 'refactor',
                    'debug', 'fix', 'sql', 'query', 'select', 'insert', 'regex',
                    'parse', 'serialize', 'format json', 'format xml',
                ],
            ],
            [
                'temperature' => 0.3,
                'keywords' => [
                    'lookup', 'define', 'what is', 'when was', 'who is', 'where is',
                    'how many', 'list', 'calculate', 'convert', 'translate', 'spell',
                    'fact', 'date', 'price', 'weather', 'capital',
                ],
            ],
            [
                'temperature' => 0.5,
                'keywords' => [
                    'how to', 'steps', 'guide', 'tutorial', 'instructions',
                    'configure', 'setup', 'install', 'migrate', 'upgrade',
                    'procedure', 'recipe', 'workflow',
                ],
            ],
            [
                'temperature' => 0.7,
                'keywords' => [
                    'analyze', 'analyse', 'compare', 'explain', 'summarize',
                    'summarise', 'describe', 'evaluate', 'review', 'assess',
                    'interpret', 'discuss', 'pros and cons', 'trade-off',
                    'tradeoff', 'recommend',
                ],
            ],
            [
                'temperature' => 0.9,
                'keywords' => [
                    'write', 'generate', 'create', 'draft', 'story', 'poem',
                    'essay', 'brainstorm', 'imagine', 'invent', 'compose',
                    'design', 'pitch', 'slogan', 'tagline', 'name ideas',
                    'creative',
                ],
            ],
        ];
    }

    /**
     * Extracts the content of the last user message.
     *
     * @param array $messages The conversation messages.
     *
     * @return string|null The content of the last user message, or null if not found.
     */
    private function extractLastUserContent(array $messages): ?string {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->getRole() === 'user') {
                return $messages[$i]->getContent();
            }
        }

        return null;
    }

    /**
     * Matches content against keyword buckets sorted by temperature ascending.
     *
     * @param string $content The lowercase message content.
     *
     * @return float The matched temperature or the default.
     */
    private function matchKeywords(string $content): float {
        // Sort buckets by temperature ascending for deterministic behavior
        $sortedBuckets = $this->buckets;

        usort($sortedBuckets, function (array $a, array $b): int {
            return $a['temperature'] <=> $b['temperature'];
        });

        foreach ($sortedBuckets as $bucket) {
            foreach ($bucket['keywords'] as $keyword) {
                if (str_contains($content, $keyword)) {
                    return (float) $bucket['temperature'];
                }
            }
        }

        return $this->default;
    }

    /**
     * Determines the structural signal cap from options.
     *
     * @param array $options The request options.
     *
     * @return float|null The cap value, or null if no cap applies.
     */
    private function determineCap(array $options): ?float {
        if (
            (isset($options['json_mode']) && $options['json_mode'] === true)
            || array_key_exists('json_schema', $options)
        ) {
            return 0.3;
        }

        if (isset($options['tools']) && !empty($options['tools'])) {
            return 0.7;
        }

        return null;
    }
}
