<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiRetrievalTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = $_ENV;
        $_ENV['AI_DRIVER'] = 'fake';
        $_ENV['DB'] = 'sqlite';
        $_ENV['DB_NAME'] = ':memory:';

        Database::reset();
        $db = Database::getInstance();

        $db->pdo()->exec('
            CREATE TABLE documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                content TEXT,
                embedding TEXT
            )
        ');

        $documents = [
            ['title' => 'SparkPHP vector search', 'content' => 'Vector queries in SparkPHP.'],
            ['title' => 'SparkPHP cache guide', 'content' => 'Caching strategies for SparkPHP.'],
            ['title' => 'Laravel queue notes', 'content' => 'Queue workers and retries.'],
        ];

        foreach ($documents as $document) {
            $embedding = ai()->embeddings($document['title'])->generate()->first();

            $stmt = $db->pdo()->prepare('INSERT INTO documents (title, content, embedding) VALUES (?, ?, ?)');
            $stmt->execute([
                $document['title'],
                $document['content'],
                json_encode($embedding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        Database::reset();

        parent::tearDown();
    }

    public function testAiRetrieveBridgesEmbeddingsAndQueryBuilder(): void
    {
        $result = ai()->retrieve('SparkPHP vector search')
            ->from('documents')
            ->select('id', 'title', 'content')
            ->take(2)
            ->get();

        $this->assertCount(2, $result->items);
        $this->assertSame('SparkPHP vector search', $result->first()->title);
        $this->assertGreaterThanOrEqual($result->items[1]->vector_score, $result->items[0]->vector_score);
        $this->assertStringContainsString('Vector queries in SparkPHP.', $result->toPromptContext('content'));
        $this->assertSame('documents', $result->meta['source']);
    }

    public function testModelSemanticSearchAndAiRetrieveCanWorkTogether(): void
    {
        $matches = AiRetrievalDocument::semanticSearch('embedding', 'SparkPHP vector search')
            ->select('id', 'title', 'content')
            ->limit(1)
            ->get();

        $this->assertCount(1, $matches);
        $this->assertInstanceOf(AiRetrievalDocument::class, $matches[0]);
        $this->assertSame('SparkPHP vector search', $matches[0]->title);

        $retrieval = ai()->retrieve('SparkPHP vector search')
            ->from(AiRetrievalDocument::class, 'embedding')
            ->take(1)
            ->get();

        $this->assertSame('SparkPHP vector search', $retrieval->first()->title);
        $this->assertSame('AiRetrievalDocument', $retrieval->meta['source']);
        $this->assertIsArray($retrieval->toArray()['items'][0]);
    }
}

final class AiRetrievalDocument extends Model
{
    protected string $table = 'documents';
}
