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

/**
 * A reusable guardrail engine that decides whether a question falls inside an
 * agent's domain, redirecting off-topic requests before any provider call.
 *
 * An {@see AgentProfile} already describes a domain via its identity, skills,
 * and constraints. `DomainBoundaries` turns that description into an enforceable
 * boundary. The primary API is {@see evaluate()}, which returns a structured
 * {@see BoundaryDecision} (blocked flag, reason, matched pattern, score) so that
 * guardrail activity is traceable and thresholds are tunable. {@see check()} is
 * a thin convenience wrapper returning just the redirect string (or null).
 *
 * The same instance can be used at two entry points: attached to an
 * {@see AgentProfile} (enforced in {@see AgentTool::execute()}) or passed per
 * request via `ChatOption::DOMAIN_BOUNDARIES` to `AbstractClient::chat()`.
 *
 * Strategies:
 * - `regex` (default): fast, deterministic pattern matching. A question matching
 *   a block pattern is redirected unless it also matches an adjacent-allowed
 *   keyword (adjacency wins). No numeric threshold applies.
 * - `keyword`: positive-scope matching. A question sharing no vocabulary with
 *   the declared in-scope terms is redirected. No numeric threshold applies.
 * - `semantic`: embedding-based scope scoring; requires an embedder callable.
 *   A question whose best cosine similarity to the domain vocabulary falls below
 *   {@see getThreshold()} is redirected. Falls back to `regex` when no embedder
 *   is provided. The threshold should be tuned from emitted `score` data, ideally
 *   using {@see isLogOnly()} shadow mode first.
 *
 * Shadow (log-only) mode: when enabled, {@see evaluate()} still computes and
 * reports the decision but never actually blocks — useful for calibrating
 * patterns and thresholds in production without user-facing impact.
 *
 * @author Ibrahim
 */
class DomainBoundaries {
    /**
     * Default redirect message used when no template is configured.
     *
     * @var string
     */
    public const DEFAULT_REDIRECT = "That's outside what I can help with. "
        .'Please ask something within my area of focus.';

    /**
     * Keywords/phrases that are tangentially related and should be allowed
     * through even if they match a block pattern.
     *
     * @var string[]
     */
    private array $adjacentAllowed;

    /**
     * Regular expression fragments (without delimiters) that mark a question as
     * out of scope.
     *
     * @var string[]
     */
    private array $blockPatterns;

    /**
     * Descriptive in-scope topics. Used by the `keyword` and `semantic`
     * strategies to build the positive-scope vocabulary; descriptive only under
     * the `regex` strategy.
     *
     * @var string[]
     */
    private array $inScope;

    /**
     * Whether the boundary runs in shadow (log-only) mode — reporting decisions
     * without actually blocking.
     *
     * @var bool
     */
    private bool $logOnly;

    /**
     * The friendly message returned when a question is out of scope.
     *
     * @var string
     */
    private string $redirectTemplate;

    /**
     * The matching strategy: 'regex' (default), 'keyword', or 'semantic'.
     *
     * @var string
     */
    private string $strategy;

    /**
     * Similarity threshold used by the `semantic` strategy (0.0 - 1.0).
     *
     * @var float
     */
    private float $threshold;

    /**
     * Creates a new DomainBoundaries instance.
     *
     * @param string[] $inScope Descriptive in-scope topics (drives keyword/semantic strategies).
     * @param string[] $adjacentAllowed Tangential keywords allowed through despite a block match.
     * @param string[] $blockPatterns Regex fragments that mark a question out of scope.
     * @param string $redirectTemplate Friendly redirect message; falls back to a default when empty.
     * @param string $strategy One of 'regex', 'keyword', or 'semantic'. Defaults to 'regex'.
     * @param float $threshold Similarity threshold for the semantic strategy.
     * @param bool $logOnly When true, report decisions without actually blocking (shadow mode).
     */
    public function __construct(
        array $inScope = [],
        array $adjacentAllowed = [],
        array $blockPatterns = [],
        string $redirectTemplate = '',
        string $strategy = 'regex',
        float $threshold = 0.5,
        bool $logOnly = false,
    ) {
        $this->inScope = $inScope;
        $this->adjacentAllowed = $adjacentAllowed;
        $this->blockPatterns = $blockPatterns;
        $this->redirectTemplate = $redirectTemplate;
        $this->strategy = in_array($strategy, ['regex', 'keyword', 'semantic'], true) ? $strategy : 'regex';
        $this->threshold = $threshold;
        $this->logOnly = $logOnly;
    }

    /**
     * Convenience wrapper returning only the redirect message.
     *
     * Returns the redirect message when the question is out of scope and
     * enforcement is active, or `null` to proceed. In shadow mode this always
     * returns `null` (never blocks); use {@see evaluate()} to observe the
     * would-be decision.
     *
     * @param string $question The user's question or delegated task.
     * @param callable|null $embedder Optional embedder for the semantic strategy.
     *
     * @return string|null The redirect message, or null if in scope / shadow mode.
     */
    public function check(string $question, ?callable $embedder = null): ?string {
        $decision = $this->evaluate($question, $embedder);

        if ($decision->isBlocked() && !$decision->isShadow()) {
            return $decision->getRedirect();
        }

        return null;
    }

    /**
     * Evaluates a question against the domain boundary and returns a structured
     * decision with full tracing metadata.
     *
     * @param string $question The user's question or delegated task.
     * @param callable|null $embedder Optional embedder for the semantic strategy.
     *        Signature: fn(string $text): float[]. When absent under the
     *        'semantic' strategy, matching falls back to 'regex'.
     *
     * @return BoundaryDecision The structured decision.
     */
    public function evaluate(string $question, ?callable $embedder = null): BoundaryDecision {
        $normalized = trim($question);

        if ($normalized === '') {
            return new BoundaryDecision(false, BoundaryDecision::REASON_IN_SCOPE, $this->strategy);
        }

        $strategy = $this->strategy;

        if ($strategy === 'semantic' && $embedder === null) {
            $strategy = 'regex';
        }

        $decision = match ($strategy) {
            'keyword' => $this->evaluateKeyword($normalized, $strategy),
            'semantic' => $this->evaluateSemantic($normalized, $embedder, $strategy),
            default => $this->evaluateRegex($normalized, $strategy),
        };

        // Shadow mode: report the would-be decision but suppress enforcement.
        if ($this->logOnly && $decision->isBlocked()) {
            return new BoundaryDecision(
                blocked: true,
                reason: $decision->getReason(),
                strategy: $decision->getStrategy(),
                redirect: $decision->getRedirect(),
                matchedPattern: $decision->getMatchedPattern(),
                score: $decision->getScore(),
                shadow: true,
            );
        }

        return $decision;
    }

    /**
     * Creates a DomainBoundaries instance from an associative array.
     *
     * Recognized snake_case keys: in_scope, adjacent_allowed, block_patterns,
     * redirect_template, strategy, threshold, log_only. All keys are optional.
     *
     * @param array<string, mixed> $data The boundary configuration.
     *
     * @return self The constructed instance.
     */
    public static function fromArray(array $data): self {
        return new self(
            inScope: $data['in_scope'] ?? [],
            adjacentAllowed: $data['adjacent_allowed'] ?? [],
            blockPatterns: $data['block_patterns'] ?? [],
            redirectTemplate: $data['redirect_template'] ?? '',
            strategy: $data['strategy'] ?? 'regex',
            threshold: isset($data['threshold']) ? (float) $data['threshold'] : 0.5,
            logOnly: (bool) ($data['log_only'] ?? false),
        );
    }

    /**
     * Returns the tangential keywords allowed through despite a block match.
     *
     * @return string[] The adjacent-allowed keywords.
     */
    public function getAdjacentAllowed(): array {
        return $this->adjacentAllowed;
    }

    /**
     * Returns the block patterns.
     *
     * @return string[] The regex fragments.
     */
    public function getBlockPatterns(): array {
        return $this->blockPatterns;
    }

    /**
     * Returns the descriptive in-scope topics.
     *
     * @return string[] The in-scope topics.
     */
    public function getInScope(): array {
        return $this->inScope;
    }

    /**
     * Returns the effective redirect message.
     *
     * Falls back to {@see DEFAULT_REDIRECT} when no template was configured.
     *
     * @return string The redirect message.
     */
    public function getRedirectTemplate(): string {
        return $this->redirectTemplate !== '' ? $this->redirectTemplate : self::DEFAULT_REDIRECT;
    }

    /**
     * Returns the matching strategy.
     *
     * @return string One of 'regex', 'keyword', or 'semantic'.
     */
    public function getStrategy(): string {
        return $this->strategy;
    }

    /**
     * Returns the semantic similarity threshold.
     *
     * Only meaningful for the 'semantic' strategy; the 'regex' and 'keyword'
     * strategies are boolean and ignore it.
     *
     * @return float The threshold (0.0 - 1.0).
     */
    public function getThreshold(): float {
        return $this->threshold;
    }

    /**
     * Returns whether the boundary runs in shadow (log-only) mode.
     *
     * @return bool True if enforcement is suppressed.
     */
    public function isLogOnly(): bool {
        return $this->logOnly;
    }

    /**
     * Exports the boundary configuration to an associative array.
     *
     * Uses snake_case keys suitable for JSON serialization and profile
     * round-tripping.
     *
     * @return array<string, mixed> The configuration data.
     */
    public function toArray(): array {
        return [
            'strategy' => $this->strategy,
            'in_scope' => $this->inScope,
            'adjacent_allowed' => $this->adjacentAllowed,
            'block_patterns' => $this->blockPatterns,
            'redirect_template' => $this->redirectTemplate,
            'threshold' => $this->threshold,
            'log_only' => $this->logOnly,
        ];
    }

    /**
     * Computes cosine similarity between two vectors.
     *
     * @param float[] $a The first vector.
     * @param float[] $b The second vector.
     *
     * @return float The cosine similarity, or 0.0 when either vector is degenerate.
     */
    private function cosineSimilarity(array $a, array $b): float {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Evaluates the keyword (positive-scope) strategy.
     *
     * The question is in scope when it shares at least one meaningful token with
     * the declared in-scope (or adjacent-allowed) vocabulary. When no in-scope
     * vocabulary is configured, everything is allowed through.
     *
     * @param string $question The normalized question.
     * @param string $strategy The effective strategy name.
     *
     * @return BoundaryDecision The decision.
     */
    private function evaluateKeyword(string $question, string $strategy): BoundaryDecision {
        $vocabulary = $this->tokenize(implode(' ', array_merge($this->inScope, $this->adjacentAllowed)));

        if (empty($vocabulary)) {
            return new BoundaryDecision(false, BoundaryDecision::REASON_IN_SCOPE, $strategy);
        }

        foreach ($this->tokenize($question) as $token) {
            if (in_array($token, $vocabulary, true)) {
                return new BoundaryDecision(
                    false,
                    BoundaryDecision::REASON_IN_SCOPE,
                    $strategy,
                    matchedPattern: $token,
                );
            }
        }

        return new BoundaryDecision(
            true,
            BoundaryDecision::REASON_BLOCKED,
            $strategy,
            redirect: $this->getRedirectTemplate(),
        );
    }

    /**
     * Evaluates the regex (block-list with adjacency override) strategy.
     *
     * A question matching a block pattern is redirected, unless it also matches
     * an adjacent-allowed keyword. Malformed patterns are skipped safely.
     *
     * @param string $question The normalized question.
     * @param string $strategy The effective strategy name.
     *
     * @return BoundaryDecision The decision.
     */
    private function evaluateRegex(string $question, string $strategy): BoundaryDecision {
        $adjacent = $this->firstMatch($this->adjacentAllowed, $question);

        if ($adjacent !== null) {
            return new BoundaryDecision(
                false,
                BoundaryDecision::REASON_ADJACENT_OVERRIDE,
                $strategy,
                matchedPattern: $adjacent,
            );
        }

        $blocked = $this->firstMatch($this->blockPatterns, $question);

        if ($blocked !== null) {
            return new BoundaryDecision(
                true,
                BoundaryDecision::REASON_BLOCKED,
                $strategy,
                redirect: $this->getRedirectTemplate(),
                matchedPattern: $blocked,
            );
        }

        return new BoundaryDecision(false, BoundaryDecision::REASON_IN_SCOPE, $strategy);
    }

    /**
     * Evaluates the semantic strategy using a caller-supplied embedder.
     *
     * Redirects when the maximum cosine similarity between the question and the
     * declared domain vocabulary falls below the configured threshold. When the
     * domain vocabulary is empty, everything is allowed through.
     *
     * @param string $question The normalized question.
     * @param callable $embedder fn(string $text): float[].
     * @param string $strategy The effective strategy name.
     *
     * @return BoundaryDecision The decision.
     */
    private function evaluateSemantic(string $question, callable $embedder, string $strategy): BoundaryDecision {
        $domainPhrases = array_merge($this->inScope, $this->adjacentAllowed);

        if (empty($domainPhrases)) {
            return new BoundaryDecision(false, BoundaryDecision::REASON_IN_SCOPE, $strategy);
        }

        $questionVector = $embedder($question);
        $best = -1.0;

        foreach ($domainPhrases as $phrase) {
            $best = max($best, $this->cosineSimilarity($questionVector, $embedder($phrase)));
        }

        if ($best < $this->threshold) {
            return new BoundaryDecision(
                true,
                BoundaryDecision::REASON_BELOW_THRESHOLD,
                $strategy,
                redirect: $this->getRedirectTemplate(),
                score: $best,
            );
        }

        return new BoundaryDecision(
            false,
            BoundaryDecision::REASON_IN_SCOPE,
            $strategy,
            score: $best,
        );
    }

    /**
     * Returns the first pattern in the list that matches the subject, or null.
     *
     * @param string[] $patterns The regex fragments to test.
     * @param string $subject The subject to test against.
     *
     * @return string|null The first matching pattern, or null if none match.
     */
    private function firstMatch(array $patterns, string $subject): ?string {
        foreach ($patterns as $pattern) {
            if ($this->safeMatch($pattern, $subject)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Performs a case-insensitive regex match, skipping malformed patterns.
     *
     * The pattern is treated as a raw fragment and wrapped in delimiters. A
     * malformed pattern never raises a warning or breaks execution — it simply
     * does not match.
     *
     * @param string $pattern The regex fragment.
     * @param string $subject The subject to test.
     *
     * @return bool True on a match, false otherwise or on a malformed pattern.
     */
    private function safeMatch(string $pattern, string $subject): bool {
        if ($pattern === '') {
            return false;
        }

        $delimited = '/'.str_replace('/', '\\/', $pattern).'/i';
        $result = @preg_match($delimited, $subject);

        return $result === 1;
    }

    /**
     * Splits text into lowercase word tokens of length >= 3.
     *
     * @param string $text The text to tokenize.
     *
     * @return string[] The lowercase tokens.
     */
    private function tokenize(string $text): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return [];
        }

        return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 3));
    }
}
