<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Rag;

use WebFiori\Ai\Tool\ToolInterface;

/**
 * Tool wrapper for RAG retrieval.
 *
 * Makes a retriever available as a tool that chat models can invoke.
 * The model decides when to search the knowledge base based on the
 * user's question.
 *
 * Example:
 * ```php
 * $retriever = new Retriever($embedProvider, $vectorStore);
 * $tool = new RetrievalTool($retriever, name: 'search_docs');
 *
 * $chatProvider->addTool($tool, fn($args) => $tool->execute($args));
 *
 * // Model can now invoke 'search_docs' when needed
 * $response = $chatProvider->chat($messages);
 * ```
 *
 * @author Ibrahim
 */
class RetrievalTool implements ToolInterface {
    /**
     * Default number of results to return.
     *
     * @var int
     */
    private int $defaultTopK;

    /**
     * Tool description for the model.
     *
     * @var string
     */
    private string $description;

    /**
     * Tool name.
     *
     * @var string
     */
    private string $name;

    /**
     * The underlying retriever.
     *
     * @var RetrieverInterface
     */
    private RetrieverInterface $retriever;

    /**
     * Creates a new RetrievalTool instance.
     *
     * @param RetrieverInterface $retriever The retriever to wrap.
     * @param string $name Tool name (default: 'search_knowledge').
     * @param string $description Tool description for the model.
     * @param int $defaultTopK Default number of results (default: 5).
     */
    public function __construct(
        RetrieverInterface $retriever,
        string $name = 'search_knowledge',
        string $description = 'Search the knowledge base for relevant information. Use this when you need to find specific facts, documentation, or context to answer a question.',
        int $defaultTopK = 5,
    ) {
        $this->retriever = $retriever;
        $this->name = $name;
        $this->description = $description;
        $this->defaultTopK = $defaultTopK;
    }

    /**
     * Executes the retrieval and formats results for the model.
     *
     * @param array<string, mixed> $arguments Tool arguments from the model:
     *        - 'query': The search query (required)
     *        - 'top_k': Number of results (optional)
     *
     * @return string JSON-encoded results for the model.
     */
    public function execute(array $arguments): string {
        $query = $arguments['query'] ?? '';

        if ($query === '') {
            return json_encode([
                'error' => 'Query is required',
                'results' => [],
            ]);
        }

        $topK = isset($arguments['top_k']) ? (int) $arguments['top_k'] : $this->defaultTopK;

        $results = $this->retriever->retrieve($query, $topK);

        if (count($results) === 0) {
            return json_encode([
                'query' => $query,
                'results' => [],
                'message' => 'No relevant information found in the knowledge base.',
            ]);
        }

        $formattedResults = array_map(
            fn(RetrievalResult $r) => $r->toArray(),
            $results,
        );

        return json_encode([
            'query' => $query,
            'results' => $formattedResults,
            'total_found' => count($results),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns the tool description.
     *
     * @return string The description.
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * Returns the tool name.
     *
     * @return string The name.
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Returns the JSON Schema for tool parameters.
     *
     * @return array<string, mixed> The parameter schema.
     */
    public function getParameters(): array {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query to find relevant information',
                ],
                'top_k' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default: ' . $this->defaultTopK . ')',
                ],
            ],
            'required' => ['query'],
        ];
    }
}
