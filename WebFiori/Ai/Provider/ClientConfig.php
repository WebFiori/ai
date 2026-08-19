<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider;

/**
 * Base configuration class for AI provider clients.
 *
 * Contains common configuration options shared across all providers.
 * Provider-specific configuration classes extend this class.
 *
 * @author Ibrahim
 */
abstract class ClientConfig {
    /**
     * Connection timeout in seconds.
     *
     * @var int
     */
    public readonly int $connectTimeout;

    /**
     * The default model to use for chat completions.
     *
     * @var string
     */
    public readonly string $model;

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    public readonly int $timeout;

    /**
     * Creates a new ClientConfig instance.
     *
     * @param string $model The default model to use for chat completions.
     * @param int $timeout Request timeout in seconds. Default is 30.
     * @param int $connectTimeout Connection timeout in seconds. Default is 10.
     */
    public function __construct(
        string $model,
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        $this->model = $model;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Converts the configuration to an associative array.
     *
     * Used internally for backward compatibility during migration.
     *
     * @return array<string, mixed> The configuration as an array.
     */
    abstract public function toArray(): array;
}
