<?php

class SparkInspectorStorage
{
    private string $basePath;
    private string $directory;
    private string $indexFile;
    private int $historyLimit;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->directory = $this->basePath . '/storage/inspector';
        $this->indexFile = $this->directory . '/index.json';
        $this->historyLimit = max(1, (int) ($_ENV['SPARK_INSPECTOR_HISTORY'] ?? 150));

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    public function save(array $entry): void
    {
        $id = $entry['id'] ?? null;
        if (!$id) {
            throw new RuntimeException('Inspector entry id is required.');
        }

        file_put_contents(
            $this->entryFile($id),
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $index = $this->readIndex();
        $index = array_values(array_filter($index, fn(array $item): bool => ($item['id'] ?? '') !== $id));
        array_unshift($index, $this->buildSummary($entry));

        if (count($index) > $this->historyLimit) {
            $stale = array_slice($index, $this->historyLimit);
            foreach ($stale as $item) {
                $file = $this->entryFile($item['id']);
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $index = array_slice($index, 0, $this->historyLimit);
        }

        $this->writeIndex($index);
    }

    public function all(): array
    {
        return $this->readIndex();
    }

    public function find(string $id): ?array
    {
        $file = $this->entryFile($id);
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function clear(): int
    {
        $count = 0;

        foreach ($this->readIndex() as $item) {
            $file = $this->entryFile($item['id']);
            if (is_file($file) && unlink($file)) {
                $count++;
            }
        }

        $this->writeIndex([]);

        return $count;
    }

    public function status(): array
    {
        $index = $this->readIndex();

        return [
            'directory' => $this->directory,
            'history_limit' => $this->historyLimit,
            'stored_requests' => count($index),
            'latest_request_id' => $index[0]['id'] ?? null,
        ];
    }

    private function buildSummary(array $entry): array
    {
        return [
            'id' => $entry['id'],
            'created_at' => $entry['created_at'] ?? date(DATE_ATOM),
            'method' => $entry['request']['method'] ?? 'GET',
            'path' => $entry['request']['path'] ?? '/',
            'status' => $entry['response']['status'] ?? 200,
            'duration_ms' => (float) ($entry['metrics']['total_ms'] ?? 0.0),
            'memory_peak_kb' => (float) ($entry['metrics']['memory_peak_kb'] ?? 0.0),
            'query_count' => count($entry['queries'] ?? []),
            'exception_count' => count($entry['exceptions'] ?? []),
        ];
    }

    private function readIndex(): array
    {
        if (!is_file($this->indexFile)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->indexFile), true);

        return is_array($data) ? $data : [];
    }

    private function writeIndex(array $index): void
    {
        file_put_contents(
            $this->indexFile,
            json_encode(array_values($index), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function entryFile(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
