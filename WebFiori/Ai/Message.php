<?php

namespace WebFiori\Ai;

/**
 * Represents a chat message with a role and content.
 */
class Message {
    private string $role;
    private string $content;

    /**
     * Creates a new message instance.
     *
     * @param string $role The role of the message sender (e.g., 'system', 'user', 'assistant').
     * @param string $content The message content.
     */
    public function __construct(string $role, string $content) {
        $this->role = $role;
        $this->content = $content;
    }

    /**
     * Returns the role of the message sender.
     */
    public function getRole(): string {
        return $this->role;
    }

    /**
     * Returns the message content.
     */
    public function getContent(): string {
        return $this->content;
    }
}
