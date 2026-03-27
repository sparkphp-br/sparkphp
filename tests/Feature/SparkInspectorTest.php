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
        $_ENV['APP_ENV'] = 'dev';
        $_ENV['SPARK_INSPECTOR'] = 'true';
        $_ENV['SPARK_INSPECTOR_PREFIX'] = '/_spark';
        $_ENV['SPARK_INSPECTOR_HISTORY'] = '5';
        $_ENV['SPARK_INSPECTOR_MASK'] = 'false';

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
        $this->assertSame(1, count($entry['dumps']));
        $this->assertGreaterThan(0, (float) $entry['metrics']['total_ms']);
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
