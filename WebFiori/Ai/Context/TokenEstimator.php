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
use WebFiori\Ai\Tool\ToolInterface;

/**
 * Estimates token counts for messages and tools.
 *
 * Uses a simple character-ratio estimation (~4 characters ≈ 1 token).
 * This is not exact but provides sufficient accuracy for context
 * window management (~5-10% margin).
 *
 * @author Ibrahim
 */
class TokenEstimator {
    /**
     * Average characters per token.
     *
     * Based on typical English text tokenization. May vary by language
     * and content type.
     *
     * @var float
     */
    private const CHARS_PER_TOKEN = 4.0;

    /**
     * Overhead tokens per message for role/structure.
     *
     * Each message has some fixed overhead for role, separators, etc.
     *
     * @var int
     */
    private const MESSAGE_OVERHEAD = 4;

    /**
     * Overhead tokens for the overall request structure.
     *
     * @var int
     */
    private const REQUEST_OVERHEAD = 3;

    /**
     * Estimates the total token count for messages and tools combined.
     *
     * @param Message[] $messages The messages to count.
     * @param ToolInterface[] $tools The tools to count.
     *
     * @return int Estimated total token count.
     */
    public function count(array $messages, array $tools = []): int {
        return $this->countMessages($messages) + $this->countTools($tools);
    }

    /**
     * Estimates the token count for a single message.
     *
     * @param Message $message The message to count.
     *
     * @return int Estimated token count.
     */
    public function countMessage(Message $message): int {
        $tokens = self::MESSAGE_OVERHEAD;

        // Content
        $tokens += $this->countText($message->getContent());

        // Role
        $tokens += $this->countText($message->getRole());

        // Tool calls
        foreach ($message->getToolCalls() as $toolCall) {
            $tokens += $this->countText($toolCall->getName());
            $tokens += $this->countText(json_encode($toolCall->getArguments()));
        }

        // Tool result
        if ($message->getToolResult() !== null) {
            $tokens += $this->countText($message->getToolResult()->getContent());
            $tokens += $this->countText($message->getToolResult()->getToolCallId());
        }

        return $tokens;
    }

    /**
     * Estimates the token count for an array of messages.
     *
     * @param Message[] $messages The messages to count.
     *
     * @return int Estimated token count.
     */
    public function countMessages(array $messages): int {
        $tokens = self::REQUEST_OVERHEAD;

        foreach ($messages as $message) {
            $tokens += $this->countMessage($message);
        }

        return $tokens;
    }

    /**
     * Estimates the token count for a text string.
     *
     * @param string $text The text to count.
     *
     * @return int Estimated token count.
     */
    public function countText(string $text): int {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estimates the token count for a single tool definition.
     *
     * @param ToolInterface $tool The tool to count.
     *
     * @return int Estimated token count.
     */
    public function countTool(ToolInterface $tool): int {
        $tokens = self::MESSAGE_OVERHEAD;

        $tokens += $this->countText($tool->getName());
        $tokens += $this->countText($tool->getDescription());
        $tokens += $this->countText(json_encode($tool->getParameters()));

        return $tokens;
    }

    /**
     * Estimates the token count for an array of tools.
     *
     * @param ToolInterface[] $tools The tools to count.
     *
     * @return int Estimated token count.
     */
    public function countTools(array $tools): int {
        $tokens = 0;

        foreach ($tools as $tool) {
            $tokens += $this->countTool($tool);
        }

        return $tokens;
    }
}
