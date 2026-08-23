<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Http\Recording;

/**
 * Manages loading, saving, and matching HTTP fixtures on disk.
 *
 * Fixtures are stored as JSON files in a directory. Filenames are
 * human-readable but decorative — matching is always done by the
 * fingerprint stored inside the fixture, never by filename.
 *
 * @author Ibrahim
 */
class FixtureCatalog {
    /**
     * Loaded fixtures indexed by fingerprint.
     *
     * @var array<string, HttpFixture>
     */
    private array $index = [];

    /**
     * Whether fixtures have been loaded from disk.
     *
     * @var bool
     */
    private bool $loaded = false;

    /**
     * The directory where fixtures are stored.
     *
     * @var string
     */
    private string $path;

    /**
     * Creates a new FixtureCatalog instance.
     *
     * @param string $path Directory path where fixtures are stored.
     *
     * @throws \InvalidArgumentException If the path does not exist.
     */
    public function __construct(string $path) {
        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Fixture directory does not exist: {$path}");
        }

        $this->path = rtrim($path, '/\\');
    }

    /**
     * Returns all loaded fixtures.
     *
     * @return HttpFixture[] All fixtures indexed by fingerprint.
     */
    public function all(): array {
        $this->ensureLoaded();

        return $this->index;
    }

    /**
     * Returns the number of loaded fixtures.
     *
     * @return int
     */
    public function count(): int {
        $this->ensureLoaded();

        return count($this->index);
    }

    /**
     * Finds a fixture matching the given fingerprint.
     *
     * @param string $fingerprint The request fingerprint to match.
     *
     * @return HttpFixture|null The matching fixture, or null if not found.
     */
    public function find(string $fingerprint): ?HttpFixture {
        $this->ensureLoaded();

        return $this->index[$fingerprint] ?? null;
    }

    /**
     * Returns the fixture directory path.
     *
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * Saves a fixture to disk.
     *
     * The filename is auto-generated from the fixture name and a short
     * fingerprint hash. If a fixture with the same fingerprint already
     * exists, it is overwritten.
     *
     * @param HttpFixture $fixture The fixture to save.
     *
     * @throws \RuntimeException If the file cannot be written.
     */
    public function save(HttpFixture $fixture): void {
        $this->ensureLoaded();

        // If a fixture with the same fingerprint already exists on disk, remove it first
        $existing = $this->index[$fixture->getFingerprint()] ?? null;

        if ($existing !== null) {
            // Find and delete the old file
            $files = glob($this->path.'/*.json') ?: [];

            foreach ($files as $file) {
                $content = file_get_contents($file);

                if ($content === false) {
                    continue;
                }

                $data = json_decode($content, true);

                if (is_array($data) && ($data['fingerprint'] ?? null) === $fixture->getFingerprint()) {
                    unlink($file);

                    break;
                }
            }
        }

        $filename = $this->buildFilename($fixture);
        $filePath = $this->path.'/'.$filename;

        $json = json_encode($fixture->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($filePath, $json) === false) {
            throw new \RuntimeException("Failed to write fixture: {$filePath}");
        }

        // Update in-memory index
        $this->index[$fixture->getFingerprint()] = $fixture;
    }

    /**
     * Builds a filename for a fixture.
     *
     * Format: {name_slug}_{short_hash}.json
     * Example: openai_chat_completions_a1b2c3d4.json
     *
     * @param HttpFixture $fixture The fixture.
     *
     * @return string The filename.
     */
    private function buildFilename(HttpFixture $fixture): string {
        $shortHash = substr($fixture->getFingerprint(), 0, 8);
        $name = $fixture->getName();

        if ($name !== '') {
            $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
            $slug = trim($slug, '_');

            return "{$slug}_{$shortHash}.json";
        }

        return "fixture_{$shortHash}.json";
    }

    /**
     * Loads all fixture files from disk into the index.
     */
    private function ensureLoaded(): void {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $files = glob($this->path.'/*.json') ?: [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);

            if (!is_array($data)) {
                continue;
            }

            try {
                $fixture = HttpFixture::fromArray($data);
                $this->index[$fixture->getFingerprint()] = $fixture;
            } catch (\Throwable) {
                // Skip malformed fixture files silently
                continue;
            }
        }
    }
}
