<?php

class Queue
{
    private string $storagePath;

    public function __construct(string $basePath)
    {
        $this->storagePath = $basePath . '/storage/queue';

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    // ── Push ──────────────────────────────────────────────────────────────────

    /** Push a job to the end of the queue. */
    public function push(string $job, mixed $data = null, string $queue = 'default'): string
    {
        $id      = uniqid('job_', true);
        $payload = [
            'id'           => $id,
            'job'          => $job,
            'data'         => $data,
            'queue'        => $queue,
            'attempts'     => 0,
            'available_at' => null,
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        $this->writeJob($queue, $payload);

        if (class_exists('SparkInspector')) {
            SparkInspector::recordQueue([
                'type' => 'push',
                'job' => $job,
                'queue' => $queue,
                'data' => $data,
            ]);
        }

        return $id;
    }

    /** Push a job that should run after $seconds delay. */
    public function later(int $seconds, string $job, mixed $data = null, string $queue = 'default'): string
    {
        $id      = uniqid('job_', true);
        $payload = [
            'id'           => $id,
            'job'          => $job,
            'data'         => $data,
            'queue'        => $queue,
            'attempts'     => 0,
            'available_at' => date('Y-m-d H:i:s', time() + $seconds),
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        $this->writeJob($queue, $payload);

        if (class_exists('SparkInspector')) {
            SparkInspector::recordQueue([
                'type' => 'later',
                'job' => $job,
                'queue' => $queue,
                'delay' => $seconds,
                'data' => $data,
            ]);
        }

        return $id;
    }

    // ── Pop & process ─────────────────────────────────────────────────────────

    /** Pop the next available job from the queue. */
    public function pop(string $queue = 'default'): ?array
    {
        $file = $this->queueFile($queue);
        $jobs = $this->read($file);
        $now  = date('Y-m-d H:i:s');

        foreach ($jobs as $i => $job) {
            if ($job['available_at'] !== null && $job['available_at'] > $now) {
                continue;
            }
            array_splice($jobs, $i, 1);
            $this->save($file, $jobs);
            return $job;
        }
        return null;
    }

    /** Process the next job in the queue. Returns true if a job was processed. */
    public function processNext(string $queue = 'default', int $maxAttempts = 3): bool
    {
        $job = $this->pop($queue);
        if ($job === null) {
            return false;
        }

        $job['attempts']++;

        try {
            $class = $job['job'];
            if (!class_exists($class)) {
                throw new \RuntimeException("Job class not found: {$class}");
            }
            $instance = new $class($job['data']);
            if (!method_exists($instance, 'handle')) {
                throw new \RuntimeException("Job {$class} must have a handle() method");
            }
            $instance->handle();
        } catch (\Throwable $e) {
            if ($job['attempts'] < $maxAttempts) {
                // Re-queue with backoff
                $job['available_at'] = date('Y-m-d H:i:s', time() + (60 * $job['attempts']));
                $this->writeJob($queue, $job);
            } else {
                // Move to failed queue
                $this->writeJob('failed', array_merge($job, [
                    'failed_at' => date('Y-m-d H:i:s'),
                    'error'     => $e->getMessage(),
                ]));
            }
        }

        return true;
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function size(string $queue = 'default'): int
    {
        return count($this->read($this->queueFile($queue)));
    }

    public function clear(string $queue = 'default'): void
    {
        $this->save($this->queueFile($queue), []);
    }

    public function queues(): array
    {
        $files = glob($this->storagePath . '/*.json') ?: [];
        return array_map(
            fn($f) => basename($f, '.json'),
            $files
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function writeJob(string $queue, array $job): void
    {
        $file = $this->queueFile($queue);
        $jobs = $this->read($file);
        $jobs[] = $job;
        $this->save($file, $jobs);
    }

    private function queueFile(string $queue): string
    {
        return $this->storagePath . "/{$queue}.json";
    }

    private function read(string $file): array
    {
        if (!file_exists($file)) return [];
        return json_decode((string)file_get_contents($file), true) ?? [];
    }

    private function save(string $file, array $jobs): void
    {
        file_put_contents($file, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
