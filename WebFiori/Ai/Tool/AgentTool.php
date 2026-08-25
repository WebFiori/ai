<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Tool;

use WebFiori\Ai\ChatOption;
use WebFiori\Ai\Message;
use WebFiori\Ai\Provider\ProviderInterface;
use WebFiori\Ai\Role;

/**
 * A tool that delegates tasks to a sub-agent powered by an AI provider.
 *
 * AgentTool wraps an AI provider and an agent profile, allowing one AI
 * model to delegate work to another agent during tool execution. The
 * sub-agent operates with its own system prompt, tools, and conversation
 * strategy.
 *
 * @author Ibrahim
 */
class AgentTool implements ToolInterface {
    /**
     * Conversation messages used in FULL_HISTORY mode.
     *
     * @var Message[]
     */
    private array $conversationContext;

    /**
     * A description of what this agent tool does.
     *
     * @var string
     */
    private string $description;

    /**
     * The message strategy controlling how context is passed to the agent.
     *
     * @var AgentMessageStrategy
     */
    private AgentMessageStrategy $messageStrategy;

    /**
     * The unique name of this agent tool.
     *
     * @var string
     */
    private string $name;

    /**
     * Extra options passed to the agent's chat() call.
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * The agent profile defining identity, skills, and instructions.
     *
     * @var AgentProfile
     */
    private AgentProfile $profile;

    /**
     * The AI provider used by the sub-agent.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $provider;

    /**
     * Creates a new AgentTool instance.
     *
     * @param string $name The unique name of this agent tool.
     * @param string $description A description of what this agent does,
     *        used by the parent model to decide when to invoke it.
     * @param ProviderInterface $provider The AI provider for the sub-agent.
     * @param AgentProfile|string $profile The agent profile or a plain system
     *        prompt string (which will be wrapped in a minimal profile).
     * @param AgentMessageStrategy $messageStrategy Controls how messages are
     *        passed to the sub-agent.
     * @param array<string, mixed> $options Extra options for the agent's chat() call.
     */
    public function __construct(
        string $name,
        string $description,
        ProviderInterface $provider,
        AgentProfile|string $profile,
        AgentMessageStrategy $messageStrategy = AgentMessageStrategy::TASK_ONLY,
        array $options = [],
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->provider = $provider;
        $this->profile = is_string($profile) ? AgentProfile::fromString($profile) : $profile;
        $this->messageStrategy = $messageStrategy;
        $this->options = $options;
        $this->conversationContext = [];
    }

    /**
     * Executes the agent tool by delegating the task to the sub-agent.
     *
     * Builds the message list based on the configured strategy, merges
     * any tools from the profile into the options, and invokes the
     * provider's chat() method.
     *
     * @param array<string, mixed> $arguments The arguments provided by the AI model.
     *        Must include a 'task' key with the delegated task string.
     *
     * @return string|ToolResponse The sub-agent's response content.
     */
    public function execute(array $arguments): string|ToolResponse {
        $task = $arguments['task'];

        $messages = [];
        $messages[] = new Message(Role::SYSTEM, $this->profile->render());

        if ($this->messageStrategy === AgentMessageStrategy::FULL_HISTORY) {
            foreach ($this->conversationContext as $message) {
                $messages[] = $message;
            }
        }

        $messages[] = new Message(Role::USER, $task);

        $options = $this->options;
        $profileTools = $this->profile->getTools();

        if (!empty($profileTools)) {
            $options[ChatOption::TOOLS] = $profileTools;
            $options[ChatOption::AUTO_EXECUTE_TOOLS] = true;
        }

        $response = $this->provider->chat($messages, $options);

        return $response->getMessage()->getContent();
    }

    /**
     * Returns the conversation context messages.
     *
     * @return Message[] The conversation context.
     */
    public function getConversationContext(): array {
        return $this->conversationContext;
    }

    /**
     * Returns a description of what this agent tool does.
     *
     * @return string The tool description.
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * Returns the message strategy.
     *
     * @return AgentMessageStrategy The strategy controlling context passing.
     */
    public function getMessageStrategy(): AgentMessageStrategy {
        return $this->messageStrategy;
    }

    /**
     * Returns the unique name of this agent tool.
     *
     * @return string The tool name.
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Returns the extra options for the agent's chat() call.
     *
     * @return array<string, mixed> The options.
     */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * Returns the JSON Schema definition for the tool's parameters.
     *
     * The agent tool accepts a single 'task' parameter describing
     * the work to delegate to the sub-agent.
     *
     * @return array<string, mixed> The parameter schema.
     */
    public function getParameters(): array {
        return [
            'type' => 'object',
            'properties' => [
                'task' => [
                    'type' => 'string',
                    'description' => 'The task or question to delegate to this agent.',
                ],
            ],
            'required' => ['task'],
        ];
    }

    /**
     * Returns the agent profile.
     *
     * @return AgentProfile The profile.
     */
    public function getProfile(): AgentProfile {
        return $this->profile;
    }

    /**
     * Returns the AI provider used by the sub-agent.
     *
     * @return ProviderInterface The provider.
     */
    public function getProvider(): ProviderInterface {
        return $this->provider;
    }

    /**
     * Sets the conversation context messages for FULL_HISTORY mode.
     *
     * @param Message[] $messages The conversation messages to include.
     */
    public function setConversationContext(array $messages): void {
        $this->conversationContext = $messages;
    }
}
