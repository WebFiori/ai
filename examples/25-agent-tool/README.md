# AgentTool — AI Agent Delegation

Delegate tasks from one AI model to another using `AgentTool`. This turns any AI provider into a callable tool, enabling multi-agent orchestration where a primary model routes subtasks to specialized agents.

## Concepts

**AgentTool** wraps an AI provider and a profile, exposing it as a `ToolInterface` that the orchestrator model can invoke. When called, it builds a message sequence and delegates to the sub-agent's `chat()` method.

**AgentProfile** provides structured onboarding: identity, skills, instructions, constraints, output format, examples, and tool references. Profiles can be created programmatically or loaded from JSON files/URLs.

**Message Strategies** control how much context the sub-agent receives:

| Strategy | Behavior |
|----------|----------|
| `TASK_ONLY` (default) | Agent gets only its system prompt + the delegated task |
| `FULL_HISTORY` | Agent gets its system prompt + full conversation history + the task |

## Loading Profiles

```php
// From constructor
$profile = new AgentProfile(
    identity: 'You are a PHP expert.',
    skills: ['OOP', 'Design patterns'],
    instructions: ['Be concise'],
);

// From JSON file
$profile = AgentProfile::fromFile(__DIR__ . '/agent-profile.json');

// From URL
$profile = AgentProfile::fromUrl('https://example.com/agents/php-expert.json');

// From plain string (minimal profile)
$profile = AgentProfile::fromString('You are a helpful coding assistant.');
```

## Agents with Sub-tools

Profiles can reference tools by name. Resolve them before use:

```php
$profile = AgentProfile::fromFile('profile.json');
// profile.json has "tools": ["search_docs"]

$profile->resolveTools([
    'search_docs' => new Tool('search_docs', '...', [...], fn($args) => '...'),
]);

// Or set tools directly
$profile->setTools([$searchTool, $calculatorTool]);
```

When the agent executes, its tools are passed via `auto_execute_tools`, enabling nested tool calling.

## Quick Start

```php
use WebFiori\Ai\Tool\AgentTool;
use WebFiori\Ai\Tool\AgentProfile;
use WebFiori\Ai\Tool\AgentMessageStrategy;

$agent = new AgentTool(
    name: 'php_expert',
    description: 'Delegates PHP questions to a specialized PHP agent.',
    provider: $googleClient,
    profile: new AgentProfile(
        identity: 'You are a senior PHP developer.',
        skills: ['PHP 8.x', 'Composer', 'PHPUnit'],
        instructions: ['Provide code examples', 'Use strict types'],
    ),
);

// Use as a tool with the orchestrator
$response = $orchestrator->chat($messages, [
    'tools' => [$agent],
    'auto_execute_tools' => true,
]);
```

## Full History Mode

```php
$agent = new AgentTool(
    name: 'context_aware_agent',
    description: 'Agent that sees the full conversation.',
    provider: $client,
    profile: $profile,
    messageStrategy: AgentMessageStrategy::FULL_HISTORY,
);

// Set conversation context before orchestrator call
$agent->setConversationContext($conversationMessages);
```

## Run Examples

```bash
php examples/25-agent-tool/basic.php
php examples/25-agent-tool/profile_from_file.php
php examples/25-agent-tool/multi_agent.php
php examples/25-agent-tool/agent_with_tools.php
```
