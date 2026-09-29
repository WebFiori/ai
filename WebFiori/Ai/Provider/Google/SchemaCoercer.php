<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Provider\Google;

/**
 * Coerces JSON-schema maps so they serialize as JSON objects, not arrays.
 *
 * PHP's json_encode() emits an empty array `[]` for an empty PHP array, but the
 * Gemini API requires schema fields such as `properties` to be JSON objects
 * `{}`. This helper recursively walks a schema and converts the maps that must
 * be objects (`properties`, `$defs`, `definitions`) — and any empty associative
 * map — into stdClass, so an empty `properties` becomes `{}` instead of `[]`.
 *
 * Used by request builders for tool `parameters` and structured-output
 * `responseSchema` on both the classic generateContent API and the
 * Interactions API.
 *
 * @internal Implementation detail of the Google provider request builders.
 *
 * @author Ibrahim
 */
final class SchemaCoercer {
    /**
     * Schema keys whose values are maps of name => sub-schema and must always
     * serialize as JSON objects, even when empty.
     *
     * @var string[]
     */
    private const OBJECT_MAP_KEYS = ['properties', '$defs', 'definitions'];

    /**
     * Recursively coerces a JSON-schema value so it serializes correctly.
     *
     * - Arrays under object-map keys (properties, $defs, definitions) become
     *   stdClass so an empty map serializes as `{}`.
     * - List arrays (e.g. `required`, `enum`) are preserved as arrays and their
     *   elements walked.
     * - Nested schemas (e.g. `items`, and each property's sub-schema) are walked
     *   recursively.
     *
     * @param mixed $schema The schema value to coerce.
     *
     * @return mixed The coerced value, safe to json_encode.
     */
    public static function coerce($schema) {
        if (!is_array($schema)) {
            return $schema;
        }

        // A list (sequential keys) is a JSON array — walk its elements but keep
        // it an array (e.g. `required`, `enum`, `anyOf`).
        if (array_is_list($schema)) {
            return array_map(static fn ($item) => self::coerce($item), $schema);
        }

        // Associative map — walk each entry, coercing object-map keys to objects.
        $result = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, self::OBJECT_MAP_KEYS, true) && is_array($value)) {
                // properties/$defs/definitions must be an object. Coerce each
                // sub-schema, then force the map itself to stdClass so an empty
                // map serializes as {} rather than [].
                $map = [];

                foreach ($value as $name => $subSchema) {
                    $map[$name] = self::coerce($subSchema);
                }

                $result[$key] = (object) $map;
            } else {
                $result[$key] = self::coerce($value);
            }
        }

        return $result;
    }
}
