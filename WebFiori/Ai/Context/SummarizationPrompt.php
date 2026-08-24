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

/**
 * The prompt used when asking a summarizer provider to condense old messages.
 *
 * Configurable so developers can customize the summarization instruction
 * for their domain (medical, legal, technical support, etc.).
 *
 * @author Ibrahim
 */
class SummarizationPrompt {
    /**
     * Default summarization instruction.
     */
    private const DEFAULT_INSTRUCTION = 'Summarize the following conversation history concisely. '
        .'Preserve key facts, decisions, names, and context that would be needed '
        .'to continue the conversation naturally. Be brief but complete.';

    /**
     * The instruction sent to the summarizer.
     *
     * @var string
     */
    private string $instruction;

    /**
     * The role to use for the summary in the conversation.
     *
     * @var string
     */
    private string $summaryPrefix;

    /**
     * Creates a new SummarizationPrompt instance.
     *
     * @param string|null $instruction Custom instruction, or null for default.
     * @param string $summaryPrefix Prefix added to the summary system message.
     */
    public function __construct(
        ?string $instruction = null,
        string $summaryPrefix = 'Summary of earlier conversation: '
    ) {
        $this->instruction = $instruction ?? self::DEFAULT_INSTRUCTION;
        $this->summaryPrefix = $summaryPrefix;
    }

    /**
     * Returns the summarization instruction.
     *
     * @return string
     */
    public function getInstruction(): string {
        return $this->instruction;
    }

    /**
     * Returns the prefix added to the generated summary.
     *
     * @return string
     */
    public function getSummaryPrefix(): string {
        return $this->summaryPrefix;
    }
}
