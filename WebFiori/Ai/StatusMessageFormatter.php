<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai;

/**
 * Formats status events into human-readable messages.
 *
 * Provides a default set of message templates that can be overridden.
 * Use this to turn robotic status codes into friendly progress messages.
 *
 * Default messages:
 * - "Preparing your request..."
 * - "Getting weather of Dubai using get_weather tool..."
 * - "Done in 3.2 seconds"
 *
 * ```php
 * $formatter = new StatusMessageFormatter();
 *
 * // Override specific messages
 * $formatter->setTemplate(Status::TOOL_CALLING,
 *     'Fetching {tool} data for {arguments.city}...'
 * );
 *
 * $client->setStatusEmitter(new CallbackStatusEmitter(
 *     function(string $status, array $context) use ($formatter) {
 *         echo $formatter->format($status, $context) . "\n";
 *     }
 * ));
 * ```
 *
 * @author Ibrahim
 */
class StatusMessageFormatter {
    /**
     * Default message templates per status.
     *
     * Placeholders use {key} syntax. Nested keys use dot notation: {arguments.city}.
     *
     * @var array<string, string>
     */
    private array $templates = [
        Status::PREPARING => 'Preparing your request...',
        Status::TRUNCATING_CONTEXT => 'Trimming conversation history to fit context window...',
        Status::SENDING_REQUEST => 'Thinking...',
        Status::WAITING_RESPONSE => 'Waiting for response...',
        Status::CACHE_HIT => 'Found cached response.',
        Status::CACHE_MISS => 'No cache found, calling AI...',
        Status::TOOL_CALLING => 'Using {tool} tool...',
        Status::TOOL_EXECUTING => 'Running {tool}...',
        Status::TOOL_COMPLETED => 'Finished {tool} in {duration_ms}ms.',
        Status::COMPLETED => 'Done in {duration_s} seconds.',
        Status::ERROR => 'Something went wrong: {error}',
    ];

    /**
     * Formats a status event into a human-readable message.
     *
     * @param string $status The status identifier.
     * @param array<string, mixed> $context The event context data.
     *
     * @return string The formatted message.
     */
    public function format(string $status, array $context = []): string {
        $template = $this->templates[$status] ?? $status;

        // Add computed values to context
        if (isset($context['duration_ms'])) {
            $context['duration_s'] = round($context['duration_ms'] / 1000, 1);
        }

        return $this->interpolate($template, $context);
    }

    /**
     * Sets a custom message template for a status.
     *
     * Available placeholders depend on the status context. Common ones:
     * - {tool}        → tool name (tool_calling, tool_executing, tool_completed)
     * - {duration_ms} → duration in milliseconds (tool_completed)
     * - {duration_s}  → duration in seconds (completed)
     * - {model}       → model name (preparing, sending_request, completed)
     * - {error}       → error message (error)
     *
     * @param string $status The status identifier. Use {@see Status} constants.
     * @param string $template The template string with {placeholder} syntax.
     *
     * @return self For method chaining.
     */
    public function setTemplate(string $status, string $template): self {
        $this->templates[$status] = $template;

        return $this;
    }

    /**
     * Sets multiple templates at once.
     *
     * @param array<string, string> $templates Map of status => template.
     *
     * @return self For method chaining.
     */
    public function setTemplates(array $templates): self {
        foreach ($templates as $status => $template) {
            $this->setTemplate($status, $template);
        }

        return $this;
    }

    /**
     * Replaces {placeholder} tokens in a template string.
     *
     * @param string $template The template string.
     * @param array<string, mixed> $context The values to substitute.
     *
     * @return string The interpolated string.
     */
    private function interpolate(string $template, array $context): string {
        return preg_replace_callback('/\{([\w.]+)\}/', function ($matches) use ($context)
        {
            $key = $matches[1];

            // Support dot notation for nested keys: {arguments.city}
            if (str_contains($key, '.')) {
                [$parent, $child] = explode('.', $key, 2);
                $value = $context[$parent][$child] ?? null;
            } else {
                $value = $context[$key] ?? null;
            }

            if (is_array($value)) {
                return json_encode($value);
            }

            return $value !== null ? (string) $value : $matches[0];
        }, $template) ?? $template;
    }
}
