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
 * Registry that maps MIME types and extensions to converters.
 *
 * Registration priority determines which converter wins when multiple
 * converters match. Higher priority wins. Built-in converters use
 * priority 0; developer converters default to 10.
 *
 * @author Ibrahim
 */
class ConverterRegistry {
    /**
     * Registered converters with their priority.
     *
     * @var array<array{converter: ConverterInterface, priority: int}>
     */
    private array $entries = [];

    /**
     * Registers a converter.
     *
     * @param ConverterInterface $converter The converter to register.
     * @param int $priority Higher priority wins. Built-ins use 0; developer
     *        converters should use 10 or higher to override built-ins.
     */
    public function register(ConverterInterface $converter, int $priority = 10): void {
        $this->entries[] = [
            'converter' => $converter,
            'priority'  => $priority,
        ];

        // Sort descending by priority so resolve() can return the first match
        usort($this->entries, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Resolves the best converter for a given MIME type and extension.
     *
     * Matching order (within same priority level):
     * 1. Extension match (most specific)
     * 2. MIME type match
     *
     * @param string $mime The detected MIME type.
     * @param string $extension The file extension (without dot, lowercase).
     *
     * @return ConverterInterface|null The best matching converter, or null if none found.
     */
    public function resolve(string $mime, string $extension): ?ConverterInterface {
        $extensionMatch = null;
        $mimeMatch = null;
        $extensionPriority = -1;
        $mimePriority = -1;

        foreach ($this->entries as $entry) {
            $converter = $entry['converter'];
            $priority  = $entry['priority'];

            // Extension match
            if ($extension !== '' && in_array($extension, $converter->getSupportedExtensions(), true)) {
                if ($extensionMatch === null || $priority > $extensionPriority) {
                    $extensionMatch = $converter;
                    $extensionPriority = $priority;
                }
            }

            // MIME match
            if (in_array($mime, $converter->getSupportedMimeTypes(), true)) {
                if ($mimeMatch === null || $priority > $mimePriority) {
                    $mimeMatch = $converter;
                    $mimePriority = $priority;
                }
            }
        }

        // Extension match wins over MIME match at same priority
        if ($extensionMatch !== null && ($mimeMatch === null || $extensionPriority >= $mimePriority)) {
            return $extensionMatch;
        }

        return $mimeMatch;
    }

    /**
     * Returns the number of registered converters.
     *
     * @return int
     */
    public function count(): int {
        return count($this->entries);
    }
}
