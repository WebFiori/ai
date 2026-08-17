<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool\FileProcessing\Converter;

use RuntimeException;
use WebFiori\Ai\Tool\FileProcessing\AbstractConverter;
use WebFiori\Ai\Tool\FileProcessing\ConversionOptions;
use WebFiori\Ai\Tool\FileProcessing\ConversionResult;
use ZipArchive;

/**
 * Converter for presentation files (PPTX).
 *
 * Uses PHP's built-in ZipArchive to extract and parse the Office Open XML
 * content slide by slide. No external dependencies required.
 *
 * Extras: page_range (string, e.g., "1-5" or "2,4,6").
 *
 * @author Ibrahim
 */
class PresentationConverter extends AbstractConverter {
    /**
     * Returns the default output format.
     *
     * @return string
     */
    public function getDefaultOutputFormat(): string {
        return 'plain_text';
    }

    /**
     * Returns supported file extensions.
     *
     * @return string[]
     */
    public function getSupportedExtensions(): array {
        return ['pptx'];
    }

    /**
     * Returns supported MIME types.
     *
     * @return string[]
     */
    public function getSupportedMimeTypes(): array {
        return [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
    }

    /**
     * Converts presentation content to plain text, slide by slide.
     *
     * @param string $content Raw file bytes.
     * @param ConversionOptions $options Conversion options.
     *
     * @return ConversionResult
     */
    public function convert(string $content, ConversionOptions $options): ConversionResult {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The "zip" PHP extension is required for PresentationConverter.');
        }
        $format    = $this->resolveFormat($options->getOutputFormat());
        $pageRange = $options->getExtra('page_range', null);

        $tmpFile = tempnam(sys_get_temp_dir(), 'wf_ppt_');
        file_put_contents($tmpFile, $content);

        try {
            [$slides, $totalSlides, $slideTitles] = $this->extractSlides($tmpFile, $pageRange);
        } finally {
            unlink($tmpFile);
        }

        $lines = [];

        foreach ($slides as $slideNum => $slideText) {
            $lines[] = "--- Slide {$slideNum} ---";
            $lines[] = $slideText;
            $lines[] = '';
        }

        $metadata = [
            'total_slides'     => $totalSlides,
            'extracted_slides' => count($slides),
            'slide_titles'     => $slideTitles,
        ];

        return $this->makeResult(
            content: trim(implode("\n", $lines)),
            maxOutput: $options->getMaxOutput(),
            mimeType: $this->getSupportedMimeTypes()[0],
            format: $format,
            metadata: $metadata,
        );
    }

    /**
     * Extracts text from slides.
     *
     * @param string $filePath
     * @param string|null $pageRange
     *
     * @return array{0: array<int, string>, 1: int}
     */
    private function extractSlides(string $filePath, ?string $pageRange): array {
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Failed to open presentation as ZIP archive.');
        }

        try {
            // Count total slides
            $totalSlides = 0;

            for ($i = 1; $i <= 500; $i++) {
                if ($zip->locateName("ppt/slides/slide{$i}.xml") === false) {
                    break;
                }

                $totalSlides++;
            }

            $allowedSlides = $this->parsePageRange($pageRange, $totalSlides);
            $slides      = [];
            $slideTitles = [];

            for ($i = 1; $i <= $totalSlides; $i++) {
                $slideXml = $zip->getFromName("ppt/slides/slide{$i}.xml");

                if ($slideXml === false) {
                    continue;
                }

                // Extract title for all slides (for metadata), text only for allowed slides
                $title = $this->getSlideTitle($slideXml);
                $slideTitles[$i] = $title ?: "Slide {$i}";

                if (!in_array($i, $allowedSlides, true)) {
                    continue;
                }

                $text = $this->parseSlideXml($slideXml);

                if ($text !== '') {
                    $slides[$i] = $text;
                }
            }

            return [$slides, $totalSlides, $slideTitles];
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts the title text from a slide XML.
     *
     * Looks for placeholder elements of type "title" or "ctrTitle".
     *
     * @param string $slideXml
     *
     * @return string The slide title, or empty string if not found.
     */
    private function getSlideTitle(string $slideXml): string {
        $doc = simplexml_load_string($slideXml);

        if ($doc === false) {
            return '';
        }

        $doc->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        // Title placeholders have type="title" or type="ctrTitle"
        $titleNodes = $doc->xpath('//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="title" or @type="ctrTitle"]]//a:t');

        if (!empty($titleNodes)) {
            $title = trim(implode(' ', array_map(fn($t) => (string) $t, $titleNodes)));

            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    /**
     * Parses a slide XML and extracts all text.
     *
     * @param string $slideXml
     *
     * @return string
     */
    private function parseSlideXml(string $slideXml): string {
        $doc = simplexml_load_string($slideXml);

        if ($doc === false) {
            return '';
        }

        $doc->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        $textParts = [];

        foreach ($doc->xpath('//a:t') as $t) {
            $text = trim((string) $t);

            if ($text !== '') {
                $textParts[] = $text;
            }
        }

        return implode(' ', $textParts);
    }

    /**
     * Parses a page range string into an array of slide numbers.
     *
     * Supports formats: "1-5", "2,4,6", "1-3,5,7-9"
     *
     * @param string|null $range
     * @param int $totalSlides
     *
     * @return int[]
     */
    private function parsePageRange(?string $range, int $totalSlides): array {
        if ($range === null) {
            return range(1, $totalSlides);
        }

        $result = [];

        foreach (explode(',', $range) as $part) {
            $part = trim($part);

            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                $result = array_merge($result, range($start, min($end, $totalSlides)));
            } else {
                $num = (int) $part;

                if ($num >= 1 && $num <= $totalSlides) {
                    $result[] = $num;
                }
            }
        }

        return array_unique($result);
    }
}
