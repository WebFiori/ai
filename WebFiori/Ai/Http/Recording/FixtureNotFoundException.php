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

use WebFiori\Ai\Http\HttpRequest;

/**
 * Thrown when ReplayHttpClient cannot find a fixture matching the request.
 *
 * Contains a descriptive message with the URL, fingerprint, fixture directory,
 * and a hint about how to create the missing fixture.
 *
 * @author Ibrahim
 */
class FixtureNotFoundException extends \RuntimeException {
    /**
     * Creates a new FixtureNotFoundException.
     *
     * @param HttpRequest $request The unmatched request.
     * @param string $fingerprint The computed fingerprint.
     * @param FixtureCatalog $catalog The catalog that was searched.
     */
    public function __construct(
        HttpRequest $request,
        string $fingerprint,
        FixtureCatalog $catalog
    ) {
        $count = $catalog->count();
        $fixtureWord = $count === 1 ? 'fixture' : 'fixtures';

        $message = sprintf(
            "No fixture matched:\n".
            "  Method: %s\n".
            "  URL: %s\n".
            "  Fingerprint: %s\n".
            "  Searched: %s (%d %s)\n".
            "  Hint: Use RecordingHttpClient to record this response, then commit the fixture file.",
            $request->getMethod(),
            $request->getUrl(),
            $fingerprint,
            $catalog->getPath(),
            $count,
            $fixtureWord
        );

        parent::__construct($message);
    }
}
