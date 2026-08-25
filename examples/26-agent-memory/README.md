# AgentMemory — Persistent Learning for AI Agents

Give your AI agents long-term memory. `AgentMemory` stores facts as vector embeddings and retrieves them via semantic similarity, enabling agents to learn from conversations and recall relevant knowledge in future interactions.

## Concepts

**AgentMemory** is the core storage class. It wraps a `VectorStorageInterface` (for persistence) and a `ProviderInterface` (for embedding generation). Facts are embedded as vectors and retrieved using cosine similarity.

**RememberStrategyInterface** controls *when* facts are automatically stored. Three built-in strategies:

| Strategy | Behavior |
|----------|----------|
| `ManualRememberStrategy` | No automatic storage. You call `remember()` explicitly. |
| `KeywordRememberStrategy` | Detects corrections/facts via regex patterns ("actually", "correction:", "remember that", etc.) |
| `LLMRememberStrategy` | Uses a classifier LLM to decide what's worth remembering from each exchange. |

**InMemoryVectorStore** is an ephemeral vector store for testing. For production, implement `VectorStorageInterface` with Pinecone, Qdrant, pgvector, etc.

## API

### remember / recall / forget

```php
use WebFiori\Ai\Embedding\InMemoryVectorStore;
use WebFiori\Ai\Tool\AgentMemory;

$memory = new AgentMemory(
    store: new InMemoryVectorStore(),
    embedder: $googleClient,    // any provider that supports embed()
    minScore: 0.7,              // filter out low-relevance results
    topK: 5,                    // max results per recall
);

// Store a fact
$id = $memory->remember('The deploy target is us-east-1.');

// Recall related facts
$results = $memory->recall('Where do we deploy?');
foreach ($results as $result) {
    echo $result->getText();    // "The deploy target is us-east-1."
    echo $result->getScore();   // 0.92
}

// Forget a fact
$memory->forget($id);
```

### Superseding (updating facts)

When information changes, supersede the old memory:

```php
$oldId = $memory->remember('Database host is db-old.example.com.');

// Later, when the info changes:
$newId = $memory->remember(
    'Database host is db-new.example.com.',
    ['reason' => 'migration'],
    supersedes: $oldId,  // deletes the old fact
);
```

## Integration with AgentTool

Pass memory and an optional strategy to `AgentTool`. The agent automatically:
1. **Recalls** relevant memories before processing a task (injected into the system prompt).
2. **Remembers** new facts after processing, based on the strategy.

```php
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentMemory;
use WebFiori\Ai\Tool\KeywordRememberStrategy;
use WebFiori\Ai\Embedding\InMemoryVectorStore;

$memory = new AgentMemory(new InMemoryVectorStore(), $embedderClient);

$agent = new AgentTool(
    name: 'support_agent',
    description: 'A support agent with persistent memory.',
    provider: $chatClient,
    profile: new AgentProfile(identity: 'You are a support agent.'),
    memory: $memory,
    rememberStrategy: new KeywordRememberStrategy(),
);
```

When the user says "Actually, the API endpoint changed to /v2/users", the `KeywordRememberStrategy` catches "actually" and stores the correction automatically.

## Integration with AbstractClient

Any `AbstractClient` (GoogleClient, OpenAIClient, etc.) can use memory directly:

```php
$client = new GoogleClient($config);
$client->setMemory($memory);
$client->setRememberStrategy(new KeywordRememberStrategy());

// Memory is automatically recalled and injected into the system message
$response = $client->chat([
    new Message('user', 'Where do we deploy?'),
]);
// Response will use recalled knowledge about deployment targets
```

## Shared Memory Across Agents

Multiple agents can share the same `AgentMemory` instance:

```php
$sharedMemory = new AgentMemory(new InMemoryVectorStore(), $embedder);

$agentA = new AgentTool(
    name: 'agent_a',
    description: 'First agent.',
    provider: $client,
    profile: $profileA,
    memory: $sharedMemory,
    rememberStrategy: new KeywordRememberStrategy(),
);

$agentB = new AgentTool(
    name: 'agent_b',
    description: 'Second agent.',
    provider: $client,
    profile: $profileB,
    memory: $sharedMemory,  // same memory instance
);

// Facts learned by agent_a are available to agent_b
```

## RememberStrategy Options

### ManualRememberStrategy (default)

No auto-learning. Call `$memory->remember()` yourself:

```php
use WebFiori\Ai\Tool\ManualRememberStrategy;

$agent = new AgentTool(
    // ...
    rememberStrategy: new ManualRememberStrategy(),
);
```

### KeywordRememberStrategy

Regex-based detection. Triggers on keywords like "actually", "correction:", "remember that":

```php
use WebFiori\Ai\Tool\KeywordRememberStrategy;

// Use default patterns
$strategy = new KeywordRememberStrategy();

// Or provide custom patterns
$strategy = new KeywordRememberStrategy([
    '/\bplease note\b/i',
    '/\bupdate:\b/i',
    '/my preference is/i',
]);
```

### LLMRememberStrategy

Uses a classifier LLM to intelligently decide what to remember:

```php
use WebFiori\Ai\Tool\LLMRememberStrategy;

$strategy = new LLMRememberStrategy(
    classifier: $cheapClient,  // use a fast/cheap model for classification
    model: 'gpt-4o-mini',     // optional model override
);
```

## Run Examples

```bash
php examples/26-agent-memory/basic.php
php examples/26-agent-memory/agent_with_memory.php
php examples/26-agent-memory/client_memory.php
```
