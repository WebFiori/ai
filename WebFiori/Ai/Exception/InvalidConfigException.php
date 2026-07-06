<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Exception;

/**
 * Thrown when provider configuration is invalid.
 *
 * This covers missing required options (API key, model, project ID),
 * invalid values (malformed endpoint URL), or incompatible option
 * combinations.
 *
 * @author Ibrahim
 */
class InvalidConfigException extends AiException {
    /**
     * The name of the configuration option that is invalid.
     *
     * @var string|null
     */
    private ?string $optionName;

    /**
     * Creates a new InvalidConfigException instance.
     *
     * @param string $message A description of the configuration error.
     * @param string|null $optionName The name of the invalid option, or null
     *        if the error is not specific to a single option.
     * @param \Throwable|null $previous The previous exception, if any.
     */
    public function __construct(string $message, ?string $optionName = null, ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->optionName = $optionName;
    }

    /**
     * Returns the name of the invalid configuration option.
     *
     * @return string|null The option name, or null if not specific to one option.
     */
    public function getOptionName(): ?string {
        return $this->optionName;
    }
}
