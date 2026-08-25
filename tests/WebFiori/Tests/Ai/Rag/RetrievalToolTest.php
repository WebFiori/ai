<?php

namespace WebFiori\Tests\Ai\Rag;

use PHPUnit\Framework\TestCase;
use WebFiori\Ai\Rag\RagProviderInterface;
use WebFiori\Ai\Rag\RetrievalResult;
use WebFiori\Ai\Rag\RetrievalTool;

class RetrievalToolTest extends TestCase {
    public function testGetName(): void {
        $retriever = $this->createMockRetriever([]);
        $tool = new RetrievalTool($retriever, name: 'search_docs');

        $this->assertEquals('search_docs', $tool->getName());
    }

    public function testGetNameDefault(): void {
        $retriever = $this->createMockRetriever([]);
        $tool = new RetrievalTool($retriever);

        $this->assertEquals('search_knowledge', $tool->getName());
    }

    public function testGetDescription(): void {
        $retriever = $this->createMockRetriever([]);
        $tool = new RetrievalTool($retriever, description: 'Custom description.');

        $this->assertEquals('Custom description.', $tool->getDescription());
    }

    public function testGetParameters(): void {
        $retriever = $this->createMockRetriever([]);
        $tool = new RetrievalTool($retriever);

        $params = $tool->getParameters();

        $this->assertEquals('object', $params['type']);
        $this->assertArrayHasKey('query', $params['properties']);
        $this->assertArrayHasKey('top_k', $params['properties']);
        $this->assertEquals(['query'], $params['required']);
    }

    public function testExecuteReturnsResults(): void {
        $retriever = $this->createMockRetriever([
            new RetrievalResult('id1', 'First result text.', 0.9, ['source' => 'doc.pdf', 'page' => 1]),
            new RetrievalResult('id2', 'Second result text.', 0.8, ['source' => 'doc.pdf', 'page' => 2]),
        ]);

        $tool = new RetrievalTool($retriever);

        $output = $tool->execute(['query' => 'test query']);
        $data = json_decode($output, true);

        $this->assertEquals('test query', $data['query']);
        $this->assertEquals(2, $data['total_found']);
        $this->assertCount(2, $data['results']);
        $this->assertEquals('First result text.', $data['results'][0]['text']);
        $this->assertEquals(0.9, $data['results'][0]['score']);
        $this->assertEquals('doc.pdf', $data['results'][0]['source']);
    }

    public function testExecuteWithNoResults(): void {
        $retriever = $this->createMockRetriever([]);

        $tool = new RetrievalTool($retriever);

        $output = $tool->execute(['query' => 'unknown topic']);
        $data = json_decode($output, true);

        $this->assertEquals('unknown topic', $data['query']);
        $this->assertCount(0, $data['results']);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('No relevant information', $data['message']);
    }

    public function testExecuteWithEmptyQueryReturnsError(): void {
        $retriever = $this->createMockRetriever([]);

        $tool = new RetrievalTool($retriever);

        $output = $tool->execute(['query' => '']);
        $data = json_decode($output, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertCount(0, $data['results']);
    }

    public function testExecuteWithMissingQueryReturnsError(): void {
        $retriever = $this->createMockRetriever([]);

        $tool = new RetrievalTool($retriever);

        $output = $tool->execute([]);
        $data = json_decode($output, true);

        $this->assertArrayHasKey('error', $data);
    }

    public function testExecuteUsesTopKParameter(): void {
        $mockRetriever = new class implements RagProviderInterface {
            public int $capturedTopK = 0;

            public function retrieve(string $query, int $topK = 5, array $options = []): array {
                $this->capturedTopK = $topK;

                return [];
            }

            public function ingest(string $content, array $metadata = []): string {
                return 'doc_mock';
            }

            public function delete(string $id): void {
            }
        };

        $tool = new RetrievalTool($mockRetriever, defaultTopK: 10);

        // Test default topK
        $tool->execute(['query' => 'test']);
        $this->assertEquals(10, $mockRetriever->capturedTopK);

        // Test custom topK
        $tool->execute(['query' => 'test', 'top_k' => 3]);
        $this->assertEquals(3, $mockRetriever->capturedTopK);
    }

    public function testExecuteHandlesUnicode(): void {
        $retriever = $this->createMockRetriever([
            new RetrievalResult('id1', 'نتيجة البحث بالعربية', 0.85, ['source' => 'arabic.pdf']),
        ]);

        $tool = new RetrievalTool($retriever);

        $output = $tool->execute(['query' => 'بحث عربي']);
        $data = json_decode($output, true);

        $this->assertEquals('نتيجة البحث بالعربية', $data['results'][0]['text']);
    }

    /**
     * Creates a mock retriever that returns the specified results.
     *
     * @param RetrievalResult[] $results
     */
    private function createMockRetriever(array $results): RagProviderInterface {
        return new class($results) implements RagProviderInterface {
            public function __construct(private array $results) {
            }

            public function retrieve(string $query, int $topK = 5, array $options = []): array {
                return $this->results;
            }

            public function ingest(string $content, array $metadata = []): string {
                return 'doc_mock';
            }

            public function delete(string $id): void {
            }
        };
    }
}
