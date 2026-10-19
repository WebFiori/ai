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
use WebFiori\Ai\Role;

/**
 * Splits a flat conversation into ordered, atomic units for pairing-safe
 * truncation inside the tool-execution loop.
 *
 * A "unit" is one of:
 * - a singleton: a single non-tool-call message (system / user / plain
 *   assistant / an orphaned tool result), wrapped in a one-element array; or
 * - a group: an assistant message carrying tool_calls followed by every tool
 *   result message that answers one of those tool_call_ids.
 *
 * Grouping lets callers drop or keep a whole tool-call interaction atomically,
 * never leaving an assistant tool_calls message without its results (or a tool
 * result without its originating call) — both of which produce provider-invalid
 * requests (OpenAI, Anthropic, Bedrock).
 *
 * This concern lives here, not in {@see ContextWindowStrategyInterface}, because
 * tool-call pairing only matters mid-loop; the pre-loop truncation path never
 * sees dangling tool pairs.
 *
 * @author Ibrahim
 */
final class ToolCallGrouping {
    /**
     * Prevent instantiation — this is a stateless helper.
     */
    private function __construct() {
    }

    /**
     * Flattens units back into a flat, ordered message list.
     *
     * @param array<int, Message[]> $units Units produced by {@see self::group()}.
     *
     * @return Message[] The flattened messages, order preserved.
     */
    public static function flatten(array $units): array {
        $messages = [];

        foreach ($units as $unit) {
            foreach ($unit as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Groups a flat message list into ordered atomic units.
     *
     * Tool result messages are attached to the most recent preceding assistant
     * group whose tool_calls include the result's tool_call_id. A tool result
     * that matches no known call becomes its own singleton (so it is never
     * silently lost, though such orphans should not occur in a well-formed
     * conversation).
     *
     * @param Message[] $messages The conversation messages.
     *
     * @return array<int, Message[]> Ordered units; each is a non-empty array of
     *         Message. Non-tool-call messages are singletons; tool-call
     *         interactions are multi-element groups.
     */
    public static function group(array $messages): array {
        $units = [];

        // Maps a tool_call_id -> index in $units of the group that owns it.
        $callIdToUnit = [];

        foreach ($messages as $message) {
            if ($message->getRole() === Role::TOOL->value) {
                $result = $message->getToolResult();
                $callId = $result !== null ? $result->getToolCallId() : '';

                if ($callId !== '' && isset($callIdToUnit[$callId])) {
                    // Attach to the owning assistant group.
                    $units[$callIdToUnit[$callId]][] = $message;
                } else {
                    // Orphan tool result: keep as its own singleton.
                    $units[] = [$message];
                }

                continue;
            }

            // Non-tool message. Start a new unit.
            $unitIndex = count($units);
            $units[$unitIndex] = [$message];

            // If this assistant message carries tool calls, register its ids so
            // subsequent tool results attach to this group.
            if ($message->hasToolCalls()) {
                foreach ($message->getToolCalls() as $call) {
                    $callIdToUnit[$call->getId()] = $unitIndex;
                }
            }
        }

        return $units;
    }
}
