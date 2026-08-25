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
 * A remember strategy that uses an LLM to classify and extract facts.
 *
 * This strategy sends the last user message and agent response to a
 * classifier LLM, which determines whether any corrections, new facts,
 * or important information should be remembered for future conversations.
 *
 * The classifier returns a JSON array of fact strings. If the response
 * cannot be parsed as valid JSON, an empty array is returned.
 *
 * @author Ibrahim
 */
class LLMRememberStrategy implements RememberStrategyInterface {
    /**
     * The AI provider used for fact classification.
     *
     * @var ProviderInterface
     */
    private ProviderInterface $classifier;

    /**
     * Optional model override for the classifier.
     *
     * @var string|null
     */
    private ?string $model;

    /**
     * Creates a new LLMRememberStrategy instance.
     *
     * @param ProviderInterface $classifier The AI provider used to classify
     *        and extract facts from conversation exchanges.
     * @param string|null $model Optional model name to use for classification.
     *        If null, the provider's default model is used.
     */
    public function __construct(
        ProviderInterface $classifier,
        ?string $model = null,
    ) {
        $this->classifier = $classifier;
        $this->model = $model;
    }

    /**
     * Extracts facts by asking an LLM to analyze the conversation exchange.
     *
     * Sends the last user message and the agent response to the classifier
     * provider, requesting a JSON array of fact strings worth remembering.
     *
     * @param Message[] $messages The conversation messages.
     * @param string $agentResponse The agent's/model's response.
     *
     * @return string[] Extracted facts, or empty array if nothing to remember
     *         or if the classifier response cannot be parsed.
     */
    public function extract(array $messages, string $agentResponse): array {
        $lastUserMessage = $this->findLastUserMessage($messages);

        if ($lastUserMessage === null) {
            return [];
        }

        $prompt = 'Analyze this conversation exchange and extract any corrections, '
            .'new facts, or important information the user provided that should be '
            ."remembered for future conversations.\n\n"
            .'User said: '.$lastUserMessage->getContent()."\n"
            .'Assistant responded: '.$agentResponse."\n\n"
            .'Return ONLY a JSON array of fact strings. Return [] if nothing worth remembering.'."\n"
            .'Example: ["WebFiori v3 uses attribute routing", '
            .'"The database supports PostgreSQL since v3.1"]';

        $options = [
            ChatOption::TEMPERATURE => 0.1,
            ChatOption::JSON_MODE => true,
        ];

        if ($this->model !== null) {
            $options[ChatOption::MODEL] = $this->model;
        }

        $response = $this->classifier->chat([
            new Message(Role::SYSTEM, 'You extract facts from conversations. Always respond with a valid JSON array of strings.'),
            new Message(Role::USER, $prompt),
        ], $options);

        $content = $response->getMessage()->getContent();
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [];
        }

        // Ensure all elements are strings
        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * Returns the AI provider used for classification.
     *
     * @return ProviderInterface The classifier provider.
     */
    public function getClassifier(): ProviderInterface {
        return $this->classifier;
    }

    /**
     * Returns the model used for classification.
     *
     * @return string|null The model name, or null if using provider default.
     */
    public function getModel(): ?string {
        return $this->model;
    }

    /**
     * Finds the last user message in the messages array.
     *
     * @param Message[] $messages The conversation messages.
     *
     * @return Message|null The last user message, or null if none found.
     */
    private function findLastUserMessage(array $messages): ?Message {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->getRole() === Role::USER->value) {
                return $messages[$i];
            }
        }

        return null;
    }
}
