# Model Aliases

Use logical names like `'fast'` or `'smart'` instead of verbose, version-specific model identifiers. Each alias maps to a different model per provider, so a single alias resolves correctly regardless of which provider handles the request.

## Basic Usage

```php
use WebFiori\Ai\ModelAliases;

$aliases = new ModelAliases([
    'fast' => [
        'openai' => 'gpt-4o-mini',
        'google' => 'gemini-2.5-flash',
        'bedrock' => 'us.amazon.nova-lite-v1:0',
    ],
    'smart' => [
        'openai' => 'gpt-4o',
        'google' => 'gemini-2.5-pro',
    ],
]);

$client->setModelAliases($aliases);
$response = $client->chat($messages, ['model' => 'fast']);
// → resolves to the provider-specific model for this client
```

## Universal Alias (all providers)

```php
$aliases = new ModelAliases([
    'embedding' => 'text-embedding-3-small', // same for all providers
]);
```

## With FallbackProvider

Each provider in the fallback chain resolves the alias independently:

```php
$openai->setModelAliases($aliases);  // 'smart' → 'gpt-4o'
$google->setModelAliases($aliases);  // 'smart' → 'gemini-2.5-pro'

$fallback = new FallbackProvider([$openai, $google]);
$response = $fallback->chat($messages, ['model' => 'smart']);
```

## Resolution Order

1. Exact provider name match (`'openai'`, `'google'`, `'bedrock'`, etc.)
2. Wildcard `'*'` — same model for all providers
3. Literal fallback — returns the string unchanged (no error)

```php
$aliases->resolve('fast', 'openai');   // → 'gpt-4o-mini'
$aliases->resolve('fast', 'unknown');  // → 'fast' (literal fallback)
$aliases->resolve('gpt-4o', 'openai'); // → 'gpt-4o' (not an alias, pass-through)
```

## Dynamic Management

```php
$aliases->add('latest', ['openai' => 'gpt-4o-2025-01-01']);
$aliases->remove('old-alias');
$aliases->has('fast'); // → true
$aliases->getAll();    // → full map
```

## Run

```bash
php examples/22-model-aliases/aliases.php
```
