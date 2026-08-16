<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool\FileProcessing;

/**
 * Options passed to a converter during file conversion.
 *
 * @author Ibrahim
 */
class ConversionOptions {
    /**
     * Extra converter-specific options (sheet_name, max_rows, page_range, etc.)
     *
     * @var array<string, mixed>
     */
    private array $extras;

    /**
     * Maximum number of characters to return.
     *
     * @var int
     */
    private int $maxOutput;

    /**
     * Requested output format.
     *
     * @var string
     */
    private string $outputFormat;

    /**
     * Creates a new ConversionOptions instance.
     *
     * @param string $outputFormat Desired output format: auto|plain_text|csv|markdown_table|json
     * @param int $maxOutput Maximum characters to return.
     * @param array<string, mixed> $extras Converter-specific extras.
     */
    public function __construct(
        string $outputFormat = 'auto',
        int $maxOutput = 50000,
        array $extras = [],
    ) {
        $this->outputFormat = $outputFormat;
        $this->maxOutput = $maxOutput;
        $this->extras = $extras;
    }

    /**
     * Returns converter-specific extra options.
     *
     * @return array<string, mixed>
     */
    public function getExtras(): array {
        return $this->extras;
    }

    /**
     * Returns a specific extra option by key.
     *
     * @param string $key The option key.
     * @param mixed $default Default value if not set.
     *
     * @return mixed
     */
    public function getExtra(string $key, mixed $default = null): mixed {
        return $this->extras[$key] ?? $default;
    }

    /**
     * Returns the maximum number of characters to return.
     *
     * @return int
     */
    public function getMaxOutput(): int {
        return $this->maxOutput;
    }

    /**
     * Returns the requested output format.
     *
     * @return string
     */
    public function getOutputFormat(): string {
        return $this->outputFormat;
    }
}
