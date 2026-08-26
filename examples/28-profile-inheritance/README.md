# Example 28: Profile Inheritance

Demonstrates how `AgentProfile` supports JSON-based inheritance via the `extends` key. Profiles can inherit from a base and override or extend fields using configurable merge strategies.

## Key Concepts

- **`extends`** — references a base profile file (stem name, no `.json` extension), resolved relative to the child file's directory
- **`inheritance_strategy`** — optional per-field override of the default merge behavior
- **Chained inheritance** — profiles can form multi-level chains (A → B → C)
- **Circular detection** — throws `RuntimeException` if a circular reference is detected

## Default Merge Strategies

| Field | Default | Behavior |
|-------|---------|----------|
| `identity` | replace | Child wins (fallback to base if empty) |
| `output_format` | replace | Child wins if present |
| `context` | replace | Child wins if present |
| `skills` | concat | Base + child combined |
| `instructions` | concat | Base + child combined |
| `constraints` | concat | Base + child combined |
| `examples` | concat | Base + child combined |
| `tools` | concat | Base + child combined |
| `metadata` | merge | Shallow merge (child keys override) |

## Override with `inheritance_strategy`

```json
{
    "extends": "support-base",
    "inheritance_strategy": {
        "skills": "replace",
        "instructions": "replace"
    },
    "skills": ["Only these skills"],
    "instructions": ["Only these instructions"]
}
```

Valid values: `concat`, `replace`, `merge`. Only `replace` is valid for scalar fields (`identity`, `output_format`, `context`).

## API

```php
// fromFile() — automatic resolution
$profile = AgentProfile::fromFile('/agents/tier1-support.json');

// fromArray() — with basePath for resolution
$profile = AgentProfile::fromArray($data, basePath: '/agents/');

// merge() — programmatic merge with optional strategy overrides
$merged = AgentProfile::merge(
    base: $baseProfile,
    child: $childProfile,
    strategies: ['skills' => 'replace'],
);
```

## Running

```bash
php examples/28-profile-inheritance/verify.php
```
