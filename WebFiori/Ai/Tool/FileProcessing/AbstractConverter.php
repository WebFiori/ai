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
 * Base class for file converters providing shared utility methods.
 *
 * @author Ibrahim
 */
abstract class AbstractConverter implements ConverterInterface {
    /**
     * Applies max_output truncation to content.
     *
     * If content exceeds the limit, it is truncated and a note is appended
     * so the model knows the response is incomplete.
     *
     * @param string $content The full content.
     * @param int $maxOutput Maximum characters.
     * @param string $mimeType MIME type for the result.
     * @param string $format Format for the result.
     * @param array<string, mixed> $metadata Metadata for the result.
     *
     * @return ConversionResult
     */
    protected function makeResult(
        string $content,
        int $maxOutput,
        string $mimeType,
        string $format,
        array $metadata = [],
    ): ConversionResult {
        $originalSize = mb_strlen($content);
        $truncated = false;

        if ($originalSize > $maxOutput) {
            $content = mb_substr($content, 0, $maxOutput);
            $content .= sprintf(
                "\n\n[...content truncated at %s characters. Original size: %s characters. ".
                "Use max_output option to increase the limit or request a specific section.]",
                number_format($maxOutput),
                number_format($originalSize)
            );
            $truncated = true;
        }

        return new ConversionResult(
            content: $content,
            format: $format,
            mimeType: $mimeType,
            truncated: $truncated,
            originalSize: $originalSize,
            metadata: $metadata,
        );
    }

    /**
     * Resolves the effective output format.
     *
     * If requested format is 'auto', returns the converter's default format.
     *
     * @param string $requestedFormat The format from ConversionOptions.
     *
     * @return string The resolved format.
     */
    protected function resolveFormat(string $requestedFormat): string {
        if ($requestedFormat === 'auto') {
            return $this->getDefaultOutputFormat();
        }

        return $requestedFormat;
    }
}
