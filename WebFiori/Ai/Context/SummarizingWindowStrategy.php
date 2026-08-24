<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Context;

use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;

/**
 * Context window strategy that summarizes old messages instead of truncating.
 *
 * When the estimated token count exceeds a threshold percentage of the model's
 * context window, this strategy:
 *
 * 1. Preserves all system messages verbatim
 * 2. Summarizes everything except the last N user/assistant turns
 * 3. Injects the summary as a system message after the original system message
 * 4. Caches the summary to avoid redundant API calls in tool loops
 *
 * ```php
 * $strategy = new SummarizingWindowStrategy(
 *     summarizer: $cheapClient,   // injected provider for summarization
 *     contextWindow: 8192,        // model's context window size
 *     threshold: 0.70,            // trigger at 70% of context window
 *     keepRecentTurns: 3,         // keep last 3 user/assistant pairs verbatim
 * );
 *
 * $client->setContextWindowStrategy($strategy);
 * ```
 *
 * @author Ibrahim
 */
class SummarizingWindowStrategy implements ContextWindowStrategyInterface {
    /**
     * Cached summary text keyed by hash of summarized messages.
     *
     * @var string|null
     */
    private ?string $cachedSummary = null;

    /**
     * Hash of the messages that produced the cached summary.
     *
     * @var string|null
     */
    private ?string $cacheKey = null;

    /**
     * Model's context window size in tokens.
     *
     * @var int
     */
    private int $contextWindow;

    /**
     * Number of recent user/assistant turns to keep verbatim.
     *
     * @var int
     */
    private int $keepRecentTurns;

    /**
     * The summarization prompt configuration.
     *
     * @var SummarizationPrompt
     */
    private SummarizationPrompt $prompt;

    /**
     * Tokens reserved for the completion response.
     *
     * @var int
     */
    private int $reserveForCompletion;

    /**
     * The provider used to generate summaries.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $summarizer;

    /**
     * Fraction of context window at which summarization triggers.
     *
     * @var float
     */
    private float $threshold;

    /**
     * Token estimator instance.
     *
     * @var TokenEstimator
     */
    private TokenEstimator $tokenEstimator;

    /**
     * Creates a new SummarizingWindowStrategy instance.
     *
     * @param ProviderInterface $summarizer The provider used to generate summaries.
     *        Use a cheap/fast model (e.g., gpt-4o-mini, gemini-2.5-flash) to
     *        minimize cost.
     * @param int $contextWindow The model's context window size in tokens.
     *        Default is 8192.
     * @param float $threshold Fraction of context window at which summarization
     *        triggers. Default is 0.70 (70%).
     * @param int $keepRecentTurns Number of recent user/assistant exchange pairs
     *        to keep verbatim. Default is 3.
     * @param int $reserveForCompletion Tokens reserved for the completion response.
     *        Default is 1024.
     * @param SummarizationPrompt|null $prompt Custom summarization prompt,
     *        or null for the default prompt.
     */
    public function __construct(
        ProviderInterface $summarizer,
        int $contextWindow = 8192,
        float $threshold = 0.70,
        int $keepRecentTurns = 3,
        int $reserveForCompletion = 1024,
        ?SummarizationPrompt $prompt = null
    ) {
        $this->summarizer = $summarizer;
        $this->contextWindow = max(1, $contextWindow);
        $this->threshold = min(1.0, max(0.1, $threshold));
        $this->keepRecentTurns = max(1, $keepRecentTurns);
        $this->reserveForCompletion = max(0, $reserveForCompletion);
        $this->prompt = $prompt ?? new SummarizationPrompt();
        $this->tokenEstimator = new TokenEstimator();
    }

    /**
     * Clears the cached summary, forcing re-summarization on the next call.
     */
    public function clearCache(): void {
        $this->cachedSummary = null;
        $this->cacheKey = null;
    }

    /**
     * Returns the context window size.
     *
     * @return int
     */
    public function getContextWindow(): int {
        return $this->contextWindow;
    }

    /**
     * Returns the number of recent turns kept verbatim.
     *
     * @return int
     */
    public function getKeepRecentTurns(): int {
        return $this->keepRecentTurns;
    }

    /**
     * Returns the summarization prompt configuration.
     *
     * @return SummarizationPrompt
     */
    public function getPrompt(): SummarizationPrompt {
        return $this->prompt;
    }

    /**
     * {@inheritdoc}
     */
    public function getReservedTokens(): int {
        return $this->reserveForCompletion;
    }

    /**
     * Returns the summarizer provider.
     *
     * @return ProviderInterface
     */
    public function getSummarizer(): ProviderInterface {
        return $this->summarizer;
    }

    /**
     * Returns the threshold fraction.
     *
     * @return float
     */
    public function getThreshold(): float {
        return $this->threshold;
    }

    /**
     * {@inheritdoc}
     *
     * If the estimated token count is below the threshold, messages are
     * returned unchanged. Otherwise, old messages are summarized and the
     * summary is injected as a system message.
     */
    public function truncate(array $messages, int $maxTokens, array $tools = []): array {
        $tokenLimit = (int) ($this->contextWindow * $this->threshold);
        $estimated = $this->tokenEstimator->count($messages, $tools);

        if ($estimated <= $tokenLimit) {
            return $messages;
        }

        return $this->summarizeAndRebuild($messages);
    }

    /**
     * Generates a summary of the given messages using the summarizer provider.
     *
     * Uses in-memory cache — if the same messages were already summarized,
     * returns the cached summary without an additional API call.
     *
     * @param Message[] $messagesToSummarize The messages to summarize.
     *
     * @return string The generated summary text.
     */
    private function generateSummary(array $messagesToSummarize): string {
        $cacheKey = hash('sha256', json_encode(array_map(
            fn(Message $m) => [$m->getRole(), $m->getContent()],
            $messagesToSummarize
        )) ?: '');

        if ($this->cachedSummary !== null && $this->cacheKey === $cacheKey) {
            return $this->cachedSummary;
        }

        // Build the summarization request
        $conversationText = implode("\n", array_map(
            fn(Message $m) => ucfirst($m->getRole()).': '.$m->getContent(),
            $messagesToSummarize
        ));

        $summaryResponse = $this->summarizer->chat([
            new Message('system', $this->prompt->getInstruction()),
            new Message('user', $conversationText),
        ]);

        $summary = $summaryResponse->getMessage()->getContent();

        $this->cachedSummary = $summary;
        $this->cacheKey = $cacheKey;

        return $summary;
    }

    /**
     * Splits messages into system messages, messages to summarize, and recent turns.
     *
     * @param Message[] $messages The full conversation messages.
     *
     * @return array{system: Message[], toSummarize: Message[], recent: Message[]}
     */
    private function splitMessages(array $messages): array {
        $systemMessages = [];
        $nonSystemMessages = [];

        foreach ($messages as $message) {
            if ($message->getRole() === 'system') {
                $systemMessages[] = $message;
            } else {
                $nonSystemMessages[] = $message;
            }
        }

        // Keep last keepRecentTurns × 2 non-system messages
        $keepCount = $this->keepRecentTurns * 2;
        $total = count($nonSystemMessages);

        if ($total <= $keepCount) {
            // Not enough messages to summarize
            return [
                'system' => $systemMessages,
                'toSummarize' => [],
                'recent' => $nonSystemMessages,
            ];
        }

        $toSummarize = array_slice($nonSystemMessages, 0, $total - $keepCount);
        $recent = array_slice($nonSystemMessages, $total - $keepCount);

        return [
            'system' => $systemMessages,
            'toSummarize' => $toSummarize,
            'recent' => $recent,
        ];
    }

    /**
     * Builds the rebuilt message array with summary injected.
     *
     * @param Message[] $messages The full conversation messages.
     *
     * @return Message[] The rebuilt messages with summary.
     */
    private function summarizeAndRebuild(array $messages): array {
        $split = $this->splitMessages($messages);

        if (empty($split['toSummarize'])) {
            // Nothing to summarize — return as-is (conversation too short)
            return $messages;
        }

        $summary = $this->generateSummary($split['toSummarize']);
        $summaryMessage = new Message(
            'system',
            $this->prompt->getSummaryPrefix().$summary
        );

        // Build: [system messages] + [summary] + [recent turns]
        return array_merge(
            $split['system'],
            [$summaryMessage],
            $split['recent']
        );
    }
}
