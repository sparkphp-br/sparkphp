<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SparkInspectorTest extends TestCase
{
    private array $serverBackup;
    private array $getBackup;
    private array $postBackup;
    private array $envBackup;
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->envBackup = $_ENV;
        $this->basePath = sys_get_temp_dir() . '/sparkphp-inspector-' . bin2hex(random_bytes(6));

        mkdir($this->basePath . '/storage/inspector', 0777, true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_ENV = $this->envBackup;

        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testStorageKeepsOnlyConfiguredHistoryLimit(): void
    {
        $_ENV['SPARK_INSPECTOR_HISTORY'] = '2';

        $storage = new SparkInspectorStorage($this->basePath);
        $storage->save($this->makeEntry('first', '/one'));
        $storage->save($this->makeEntry('second', '/two'));
        $storage->save($this->makeEntry('third', '/three'));

        $index = $storage->all();

        $this->assertCount(2, $index);
        $this->assertSame('third', $index[0]['id']);
        $this->assertSame('second', $index[1]['id']);
        $this->assertNull($storage->find('first'));
        $this->assertNotNull($storage->find('third'));
    }

    public function testDecoratesResponsesWithInspectorHeadersAndPersistsTheRequest(): void
    {
        $this->enableInspector();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/users?page=1';
        $_SERVER['HTTP_HOST'] = 'sparkphp.test';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_GET = ['page' => '1'];
        $_POST = [];

        SparkInspector::boot($this->basePath);

        $request = new Request();
        SparkInspector::startRequest($request);
        SparkInspector::recordQuery('select * from users where id = ?', [1], 4.2, 1);
        SparkInspector::recordView('users/index', 'view', 1.7, false, true);
        SparkInspector::recordCache('get', 'users:1', ['hit' => true]);
        SparkInspector::recordCache('get', 'users:missing', ['miss' => true]);
        SparkInspector::recordCache('flexible', 'reports', ['hit' => true, 'stale' => true]);
        SparkInspector::recordCache('set', 'users:1', ['ttl' => 60]);
        SparkInspector::recordQueue(['type' => 'dispatch', 'job' => 'SendEmailJob', 'queue' => 'emails', 'tries' => 3]);
        SparkInspector::recordQueue(['type' => 'push', 'job' => 'SendEmailJob', 'queue' => 'emails', 'tries' => 3]);
        SparkInspector::recordQueue(['type' => 'later', 'job' => 'CleanupJob', 'queue' => 'low', 'tries' => 1, 'delay' => 30]);
        SparkInspector::recordQueue(['type' => 'released', 'job' => 'CleanupJob', 'queue' => 'low', 'attempts' => 1, 'tries' => 2, 'delay' => 60, 'error' => 'Temporary failure']);
        SparkInspector::recordQueue(['type' => 'retry', 'job' => 'CleanupJob', 'queue' => 'low', 'tries' => 2]);
        SparkInspector::recordQueue(['type' => 'failed', 'job' => 'CleanupJob', 'queue' => 'failed', 'attempts' => 2, 'tries' => 2, 'error' => 'Permanent failure']);
        SparkInspector::inspect(['ok' => true]);

        usleep(1000);

        $response = Response::json(['ok' => true]);
        SparkInspector::decorateResponse($response);

        $headers = $response->getHeaders();
        $requestId = $headers['X-Spark-Request-Id'] ?? null;

        $this->assertNotNull($requestId);
        $this->assertSame('/_spark/requests/' . $requestId, $headers['X-Spark-Inspector-Url'] ?? null);
        $this->assertMatchesRegularExpression('/total;dur=\d+\.\d+, db;dur=4\.200, view;dur=1\.700/', $headers['Server-Timing'] ?? '');
        $this->assertSame('{"ok":true}', $response->getBody());

        SparkInspector::finalizeResponse($response);

        $entry = (new SparkInspectorStorage($this->basePath))->find((string) $requestId);

        $this->assertIsArray($entry);
        $this->assertSame('/api/users', $entry['request']['path']);
        $this->assertSame(200, $entry['response']['status']);
        $this->assertSame(1, count($entry['queries']));
        $this->assertSame(1, count($entry['views']));
        $this->assertSame(4, count($entry['cache']));
        $this->assertSame(1, count($entry['dumps']));
        $this->assertSame(4, $entry['metrics']['cache_ops']);
        $this->assertSame(2, $entry['metrics']['cache_hits']);
        $this->assertSame(1, $entry['metrics']['cache_misses']);
        $this->assertSame(1, $entry['metrics']['cache_stale_hits']);
        $this->assertSame(1, $entry['metrics']['cache_writes']);
        $this->assertSame(6, $entry['metrics']['queue_ops']);
        $this->assertSame(1, $entry['metrics']['queue_dispatches']);
        $this->assertSame(2, $entry['metrics']['queue_enqueued']);
        $this->assertSame(1, $entry['metrics']['queue_delayed']);
        $this->assertSame(1, $entry['metrics']['queue_released']);
        $this->assertSame(1, $entry['metrics']['queue_failed']);
        $this->assertSame(1, $entry['metrics']['queue_retries']);
        $this->assertGreaterThan(0, (float) $entry['metrics']['total_ms']);
        $this->assertSame('/api/users', $entry['pipelines']['request']['summary']['Path']);
        $this->assertSame('4', $entry['pipelines']['cache']['summary']['Ops']);
        $this->assertSame('6', $entry['pipelines']['queue']['summary']['Ops']);
        $this->assertSame('CleanupJob', $entry['bottlenecks']['most_fragile_job']['job']);
        $this->assertSame('users:1', $entry['bottlenecks']['noisiest_cache_key']['key']);
    }

    public function testInternalRequestsPageRendersStoredHistoryWithoutToolbarInjection(): void
    {
        $this->enableInspector();

        $storage = new SparkInspectorStorage($this->basePath);
        $storage->save($this->makeEntry('a1b2c3d4', '/one'));
        $storage->save($this->makeEntry('b2c3d4e5', '/two'));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_spark/requests';
        $_SERVER['HTTP_HOST'] = 'sparkphp.test';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_GET = [];
        $_POST = [];

        SparkInspector::boot($this->basePath);

        $request = new Request();
        SparkInspector::startRequest($request);

        ob_start();
        $handled = SparkInspector::handleInternalRequest($request);
        $output = (string) ob_get_clean();

        $this->assertTrue($handled);
        $this->assertStringContainsString('Spark Inspector', $output);
        $this->assertStringContainsString('/one', $output);
        $this->assertStringContainsString('/two', $output);
        $this->assertStringNotContainsString('spark-inspector-toolbar', $output);
    }

    public function testInternalRequestApiReturnsStoredPayloadAsJson(): void
    {
        $this->enableInspector();

        $entry = $this->makeEntry('c3d4e5f6', '/api/users');
        $entry['queries'][] = [
            'sql' => 'select * from users',
            'bindings' => [],
            'duration_ms' => 1.2,
            'row_count' => 1,
        ];

        (new SparkInspectorStorage($this->basePath))->save($entry);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/_spark/api/requests/c3d4e5f6';
        $_SERVER['HTTP_HOST'] = 'sparkphp.test';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_GET = [];
        $_POST = [];

        SparkInspector::boot($this->basePath);

        $request = new Request();
        SparkInspector::startRequest($request);

        ob_start();
        $handled = SparkInspector::handleInternalRequest($request);
        $output = (string) ob_get_clean();

        $payload = json_decode($output, true);

        $this->assertTrue($handled);
        $this->assertIsArray($payload);
        $this->assertSame('c3d4e5f6', $payload['id']);
        $this->assertSame('/api/users', $payload['request']['path']);
        $this->assertCount(1, $payload['queries']);
    }

    public function testInspectorTracksAiMetricsPipelinesAndMasking(): void
    {
        $this->enableInspector();
        $_ENV['SPARK_AI_MASK'] = 'true';
        $_ENV['SPARK_AI_TRACE_PREVIEW'] = '64';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/assistant';
        $_SERVER['HTTP_HOST'] = 'sparkphp.test';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_GET = [];
        $_POST = [];

        SparkInspector::boot($this->basePath);

        $request = new Request();
        SparkInspector::startRequest($request);
        SparkInspector::recordAi([
            'type' => 'text',
            'driver' => 'fake',
            'provider' => 'fake',
            'model' => 'spark-text',
            'status' => 'ok',
            'duration_ms' => 12.5,
            'request' => [
                'prompt' => 'Segredo comercial do cliente XPTO',
                'system' => 'Nao exponha informacoes sensiveis.',
            ],
            'response' => [
                'text' => 'Resposta curta sobre o cliente XPTO',
            ],
            'tokens' => ['input' => 24, 'output' => 18, 'total' => 42],
            'cost_usd' => 0.0012,
        ]);
        SparkInspector::recordAi([
            'type' => 'agent',
            'driver' => 'fake',
            'provider' => 'fake',
            'model' => 'spark-agent',
            'status' => 'ok',
            'duration_ms' => 25.0,
            'request' => [
                'prompt' => 'Analise o ticket 42.',
                'instructions' => 'Use o contexto interno somente para auditoria.',
                'tools' => [['name' => 'lookup-ticket']],
            ],
            'response' => [
                'text' => 'Ticket 42 esta aberto.',
                'tool_results' => ['lookup-ticket' => ['id' => 42]],
            ],
            'tokens' => ['input' => 30, 'output' => 10, 'total' => 40],
            'tool_calls' => 1,
            'cost_usd' => 0.0035,
        ]);

        $response = Response::json(['ok' => true]);
        SparkInspector::decorateResponse($response);
        $headers = $response->getHeaders();
        SparkInspector::finalizeResponse($response);

        $entry = (new SparkInspectorStorage($this->basePath))->find((string) ($headers['X-Spark-Request-Id'] ?? ''));

        $this->assertIsArray($entry);
        $this->assertCount(2, $entry['ai']);
        $this->assertSame(2, $entry['metrics']['ai_ops']);
        $this->assertSame(1, $entry['metrics']['ai_text_ops']);
        $this->assertSame(1, $entry['metrics']['ai_agent_ops']);
        $this->assertSame(1, $entry['metrics']['ai_tool_calls']);
        $this->assertSame(82, $entry['metrics']['ai_tokens_total']);
        $this->assertSame(0.0047, round((float) $entry['metrics']['ai_cost_usd'], 4));
        $this->assertSame('2', $entry['pipelines']['ai']['summary']['Ops']);
        $this->assertSame('82', $entry['pipelines']['ai']['summary']['Total Tokens']);
        $this->assertSame('fake', $entry['pipelines']['ai']['summary']['Providers']);
        $this->assertSame('agent', $entry['bottlenecks']['slowest_ai_call']['type']);
        $this->assertSame('agent', $entry['bottlenecks']['most_expensive_ai_call']['type']);
        $this->assertStringContainsString('ai;dur=37.500', $headers['Server-Timing'] ?? '');
        $this->assertStringContainsString('[masked prompt len=', $entry['ai'][0]['request']['prompt']);
        $this->assertStringContainsString('[masked text len=', $entry['ai'][0]['response']['text']);
        $this->assertStringContainsString('[masked tool_results items=', $entry['ai'][1]['response']['tool_results']);
    }

    private function makeEntry(string $id, string $path): array
    {
        return [
            'id' => $id,
            'created_at' => '2026-03-27T12:00:00+00:00',
            'request' => [
                'method' => 'GET',
                'path' => $path,
            ],
            'response' => [
                'status' => 200,
            ],
            'queries' => [],
            'exceptions' => [],
            'metrics' => [
                'total_ms' => 10.5,
                'memory_peak_kb' => 256.0,
            ],
        ];
    }

    private function enableInspector(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $_ENV['SPARK_INSPECTOR'] = 'true';
        $_ENV['SPARK_INSPECTOR_PREFIX'] = '/_spark';
        $_ENV['SPARK_INSPECTOR_HISTORY'] = '5';
        $_ENV['SPARK_INSPECTOR_MASK'] = 'false';
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
