<?php

#[\Attribute(\Attribute::TARGET_CLASS)]
class OnQueue
{
    public function __construct(
        public string $name,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class QueueConnection
{
    public function __construct(
        public string $name,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class Tries
{
    public function __construct(
        public int $count,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class Backoff
{
    public function __construct(
        public int|array $seconds,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class Timeout
{
    public function __construct(
        public int|float $seconds,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
class FailOnTimeout
{
    public function __construct(
        public bool $enabled = true,
    ) {}
}

class QueueTimeoutException extends \RuntimeException
{
}

class Queue
{
    private const FAILED_QUEUE = 'failed';

    private static array $routes = [];

    private string $basePath;
    private string $storagePath;
    private array $manifestDefaults = [];
    private array $manifestRoutes = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->storagePath = $this->basePath . '/storage/queue';

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $this->loadManifestRoutes();
    }

    public static function route(
        string $jobClass,
        ?string $queue = null,
        ?string $connection = null,
        ?int $tries = null,
        int|array|null $backoff = null,
        int|float|null $timeout = null,
        ?bool $failOnTimeout = null,
    ): void {
        static::$routes[$jobClass] = array_filter([
            'queue' => $queue,
            'connection' => $connection,
            'tries' => $tries,
            'backoff' => $backoff,
            'timeout' => $timeout,
            'fail_on_timeout' => $failOnTimeout,
        ], static fn(mixed $value): bool => $value !== null);
    }

    public static function flushRoutes(): void
    {
        static::$routes = [];
    }

    public function resolveDispatchOptions(string $jobClass, array $overrides = []): array
    {
        $options = array_merge(
            $this->defaultOptions(),
            $this->manifestDefaults,
            $this->jobOptionsFor($jobClass),
            $this->manifestRoutes[$jobClass] ?? [],
            static::$routes[$jobClass] ?? [],
            $this->normalizeOptions($overrides)
        );

        $options['queue'] = (string) ($options['queue'] ?? 'default');
        $options['connection'] = in_array($options['connection'] ?? 'sync', ['sync', 'file'], true)
            ? (string) $options['connection']
            : 'sync';
        $options['tries'] = max(1, (int) ($options['tries'] ?? 3));
        $options['backoff'] = $this->normalizeBackoff($options['backoff'] ?? [60, 120, 300]);
        $options['timeout'] = max(0, (float) ($options['timeout'] ?? 0));
        $options['fail_on_timeout'] = (bool) ($options['fail_on_timeout'] ?? false);

        return $options;
    }

    public function push(string $job, mixed $data = null, ?string $queue = null, array $options = []): string
    {
        $payload = $this->createPayload($job, $data, $queue, $options);

        $this->writeJob($payload['queue'], $payload);
        $this->record('push', $payload);

        return $payload['id'];
    }

    public function later(int $seconds, string $job, mixed $data = null, ?string $queue = null, array $options = []): string
    {
        $payload = $this->createPayload($job, $data, $queue, $options);
        $payload['available_at'] = $this->timestamp(time() + max(0, $seconds));

        $this->writeJob($payload['queue'], $payload);
        $this->record('later', $payload + ['delay' => $seconds]);

        return $payload['id'];
    }

    public function dispatchSync(string $job, mixed $data = null, ?string $queue = null, array $options = []): mixed
    {
        $payload = $this->createPayload($job, $data, $queue, $options);
        $payload['connection'] = 'sync';
        $payload['attempts'] = 1;
        $payload['started_at'] = $this->now();
        $payload['reserved_at'] = $payload['started_at'];

        $instance = $this->instantiateJob($payload['job'], $payload['data']);
        $result = $this->runInstance($instance, (float) $payload['timeout']);

        $this->record('dispatch-sync', $payload);

        return $result;
    }

    public function pop(string $queue = 'default'): ?array
    {
        $file = $this->queueFile($queue);
        $jobs = $this->read($file);
        $now = $this->now();

        foreach ($jobs as $index => $job) {
            if (($job['available_at'] ?? null) !== null && $job['available_at'] > $now) {
                continue;
            }

            array_splice($jobs, $index, 1);
            $this->save($file, $jobs);

            return $job;
        }

        return null;
    }

    public function workOnce(string $queue = 'default', int $defaultTries = 3): ?array
    {
        $job = $this->pop($queue);
        if ($job === null) {
            return null;
        }

        $job['queue'] = $job['queue'] ?? $queue;
        $job['tries'] = max(1, (int) ($job['tries'] ?? $defaultTries));
        $job['backoff'] = $this->normalizeBackoff($job['backoff'] ?? [60, 120, 300]);
        $job['timeout'] = max(0, (float) ($job['timeout'] ?? 0));
        $job['fail_on_timeout'] = (bool) ($job['fail_on_timeout'] ?? false);
        $job['attempts'] = ((int) ($job['attempts'] ?? 0)) + 1;
        $job['reserved_at'] = $this->now();
        $job['started_at'] = $job['reserved_at'];

        try {
            $instance = $this->instantiateJob($job['job'], $job['data'] ?? null);
            $result = $this->runInstance($instance, (float) $job['timeout']);

            $job['finished_at'] = $this->now();

            $this->record('processed', $job);

            return [
                'status' => 'processed',
                'id' => $job['id'],
                'job' => $job['job'],
                'queue' => $job['queue'],
                'attempts' => $job['attempts'],
                'tries' => $job['tries'],
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $job['last_error'] = $e->getMessage();
            $job['last_error_class'] = $e::class;
            $job['last_error_at'] = $this->now();
            $job['finished_at'] = $job['last_error_at'];

            $isTimeout = $e instanceof QueueTimeoutException;
            $shouldFailNow = $isTimeout && $job['fail_on_timeout'];
            $canRetry = !$shouldFailNow && $job['attempts'] < $job['tries'];

            if ($canRetry) {
                $delay = $this->backoffForAttempt($job['backoff'], $job['attempts']);
                $job['available_at'] = $this->timestamp(time() + $delay);
                $this->writeJob($job['queue'], $job);

                $this->record('released', $job + [
                    'delay' => $delay,
                    'failure_reason' => $isTimeout ? 'timeout' : 'exception',
                ]);

                return [
                    'status' => 'released',
                    'id' => $job['id'],
                    'job' => $job['job'],
                    'queue' => $job['queue'],
                    'attempts' => $job['attempts'],
                    'tries' => $job['tries'],
                    'delay' => $delay,
                    'error' => $e->getMessage(),
                    'failure_reason' => $isTimeout ? 'timeout' : 'exception',
                ];
            }

            $failedPayload = $job;
            $failedPayload['failed_at'] = $this->now();
            $failedPayload['failure_reason'] = $isTimeout ? 'timeout' : 'exception';

            $this->writeJob(self::FAILED_QUEUE, $failedPayload);

            $this->record('failed', $failedPayload);

            return [
                'status' => 'failed',
                'id' => $failedPayload['id'],
                'job' => $failedPayload['job'],
                'queue' => $failedPayload['queue'],
                'attempts' => $failedPayload['attempts'],
                'tries' => $failedPayload['tries'],
                'error' => $e->getMessage(),
                'failure_reason' => $failedPayload['failure_reason'],
            ];
        }
    }

    public function processNext(string $queue = 'default', int $defaultTries = 3): bool
    {
        return $this->workOnce($queue, $defaultTries) !== null;
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->read($this->queueFile($queue)));
    }

    public function jobs(string $queue = 'default'): array
    {
        return $this->read($this->queueFile($queue));
    }

    public function stats(string $queue = 'default'): array
    {
        $jobs = $this->jobs($queue);
        $now = $this->now();
        $ready = 0;
        $delayed = 0;

        foreach ($jobs as $job) {
            if (($job['available_at'] ?? null) !== null && $job['available_at'] > $now) {
                $delayed++;
                continue;
            }

            $ready++;
        }

        return [
            'queue' => $queue,
            'total' => count($jobs),
            'ready' => $ready,
            'delayed' => $delayed,
        ];
    }

    public function clear(string $queue = 'default', array $filters = []): int
    {
        $file = $this->queueFile($queue);
        $jobs = $this->read($file);
        $filters = $this->normalizeOptions($filters);

        if ($filters === []) {
            $removed = count($jobs);
            $this->save($file, []);
            return $removed;
        }

        $kept = [];
        $removed = 0;

        foreach ($jobs as $job) {
            if ($this->matchesFilters($job, $filters)) {
                $removed++;
                continue;
            }

            $kept[] = $job;
        }

        $this->save($file, $kept);

        return $removed;
    }

    public function queues(): array
    {
        $files = glob($this->storagePath . '/*.json') ?: [];
        $queues = array_map(
            static fn(string $file): string => basename($file, '.json'),
            $files
        );

        sort($queues);

        return $queues;
    }

    public function inspect(string $id, ?string $queue = null): ?array
    {
        $queues = $queue !== null ? [$queue] : $this->queues();

        foreach ($queues as $name) {
            foreach ($this->jobs($name) as $job) {
                if (($job['id'] ?? null) !== $id) {
                    continue;
                }

                return [
                    'queue' => $name,
                    'job' => $job,
                ];
            }
        }

        return null;
    }

    public function retry(string $id, string $sourceQueue = self::FAILED_QUEUE, ?string $targetQueue = null): bool
    {
        $file = $this->queueFile($sourceQueue);
        $jobs = $this->read($file);

        foreach ($jobs as $index => $job) {
            if (($job['id'] ?? null) !== $id) {
                continue;
            }

            array_splice($jobs, $index, 1);
            $this->save($file, $jobs);
            $this->releaseRetry($job, $targetQueue);

            return true;
        }

        return false;
    }

    public function retryAll(string $sourceQueue = self::FAILED_QUEUE, ?string $targetQueue = null): int
    {
        $jobs = $this->jobs($sourceQueue);
        if ($jobs === []) {
            return 0;
        }

        $this->save($this->queueFile($sourceQueue), []);

        foreach ($jobs as $job) {
            $this->releaseRetry($job, $targetQueue);
        }

        return count($jobs);
    }

    private function createPayload(string $job, mixed $data, ?string $queue, array $options): array
    {
        $resolved = $this->resolveDispatchOptions($job, array_merge(
            $options,
            $queue !== null ? ['queue' => $queue] : []
        ));

        return [
            'id' => uniqid('job_', true),
            'job' => $job,
            'data' => $data,
            'queue' => $resolved['queue'],
            'connection' => $resolved['connection'],
            'attempts' => 0,
            'tries' => $resolved['tries'],
            'backoff' => $resolved['backoff'],
            'timeout' => $resolved['timeout'],
            'fail_on_timeout' => $resolved['fail_on_timeout'],
            'available_at' => null,
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'last_error' => null,
            'last_error_class' => null,
            'last_error_at' => null,
            'created_at' => $this->now(),
        ];
    }

    private function instantiateJob(string $class, mixed $data): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Job class not found: {$class}");
        }

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $instance = $reflection->newInstance();
        } else {
            $instance = $reflection->newInstance($data);
        }

        if (!method_exists($instance, 'handle')) {
            throw new \RuntimeException("Job {$class} must have a handle() method");
        }

        return $instance;
    }

    private function runInstance(object $instance, float $timeout): mixed
    {
        $startedAt = microtime(true);

        try {
            return $this->invokeHandle($instance);
        } finally {
            $elapsed = microtime(true) - $startedAt;
            if ($timeout > 0 && $elapsed > $timeout) {
                throw new QueueTimeoutException(sprintf(
                    'Job exceeded timeout of %.3F second(s).',
                    $timeout
                ));
            }
        }
    }

    private function invokeHandle(object $instance): mixed
    {
        try {
            $container = app()->getContainer();
        } catch (\Throwable) {
            $container = null;
        }

        if ($container instanceof Container) {
            return $container->call([$instance, 'handle']);
        }

        return $instance->handle();
    }

    private function releaseRetry(array $job, ?string $targetQueue = null): void
    {
        $queue = $targetQueue ?? ($job['queue'] ?? 'default');
        $job['queue'] = $queue;
        $job['attempts'] = 0;
        $job['available_at'] = null;
        $job['reserved_at'] = null;
        $job['started_at'] = null;
        $job['finished_at'] = null;
        $job['retried_at'] = $this->now();
        $job['retry_count'] = ((int) ($job['retry_count'] ?? 0)) + 1;

        if (isset($job['failed_at'])) {
            $job['last_failed_at'] = $job['failed_at'];
            unset($job['failed_at']);
        }

        if (isset($job['failure_reason'])) {
            $job['last_failure_reason'] = $job['failure_reason'];
            unset($job['failure_reason']);
        }

        $this->writeJob($queue, $job);
        $this->record('retry', $job);
    }

    private function loadManifestRoutes(): void
    {
        $file = $this->basePath . '/app/jobs/_queue.php';
        if (!is_file($file)) {
            return;
        }

        $manifest = require $file;
        if (!is_array($manifest)) {
            return;
        }

        $defaults = $manifest['defaults'] ?? [];
        $routes = $manifest['routes'] ?? array_diff_key($manifest, ['defaults' => true]);

        if (is_array($defaults)) {
            $this->manifestDefaults = $this->normalizeOptions($defaults);
        }

        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $jobClass => $options) {
            if (!is_string($jobClass) || !is_array($options)) {
                continue;
            }

            $this->manifestRoutes[$jobClass] = $this->normalizeOptions($options);
        }
    }

    private function jobOptionsFor(string $jobClass): array
    {
        if (!class_exists($jobClass)) {
            return [];
        }

        $reflection = new \ReflectionClass($jobClass);
        $defaults = $reflection->getDefaultProperties();
        $options = [];

        foreach (['queue', 'connection', 'tries', 'backoff', 'timeout'] as $property) {
            if (array_key_exists($property, $defaults)) {
                $options[$property] = $defaults[$property];
            }
        }

        if (array_key_exists('failOnTimeout', $defaults)) {
            $options['fail_on_timeout'] = $defaults['failOnTimeout'];
        }

        if (array_key_exists('fail_on_timeout', $defaults)) {
            $options['fail_on_timeout'] = $defaults['fail_on_timeout'];
        }

        foreach ($reflection->getAttributes(OnQueue::class) as $attribute) {
            $options['queue'] = $attribute->newInstance()->name;
        }

        foreach ($reflection->getAttributes(QueueConnection::class) as $attribute) {
            $options['connection'] = $attribute->newInstance()->name;
        }

        foreach ($reflection->getAttributes(Tries::class) as $attribute) {
            $options['tries'] = $attribute->newInstance()->count;
        }

        foreach ($reflection->getAttributes(Backoff::class) as $attribute) {
            $options['backoff'] = $attribute->newInstance()->seconds;
        }

        foreach ($reflection->getAttributes(Timeout::class) as $attribute) {
            $options['timeout'] = $attribute->newInstance()->seconds;
        }

        foreach ($reflection->getAttributes(FailOnTimeout::class) as $attribute) {
            $options['fail_on_timeout'] = $attribute->newInstance()->enabled;
        }

        return $this->normalizeOptions($options);
    }

    private function defaultOptions(): array
    {
        return [
            'queue' => 'default',
            'connection' => $_ENV['QUEUE'] ?? 'sync',
            'tries' => 3,
            'backoff' => [60, 120, 300],
            'timeout' => 0,
            'fail_on_timeout' => false,
        ];
    }

    private function normalizeOptions(array $options): array
    {
        if (array_key_exists('failOnTimeout', $options)) {
            $options['fail_on_timeout'] = $options['failOnTimeout'];
            unset($options['failOnTimeout']);
        }

        return array_filter(
            $options,
            static fn(mixed $value): bool => $value !== null
        );
    }

    private function normalizeBackoff(mixed $backoff): int|array
    {
        if (is_string($backoff) && str_contains($backoff, ',')) {
            $backoff = array_map('trim', explode(',', $backoff));
        }

        if (is_array($backoff)) {
            $values = array_values(array_filter(
                array_map(static fn(mixed $value): int => max(0, (int) $value), $backoff),
                static fn(int $value): bool => $value >= 0
            ));

            return $values === [] ? 0 : $values;
        }

        return max(0, (int) $backoff);
    }

    private function backoffForAttempt(int|array $backoff, int $attempts): int
    {
        if (is_array($backoff)) {
            $index = max(0, min($attempts - 1, count($backoff) - 1));
            return max(0, (int) ($backoff[$index] ?? 0));
        }

        return max(0, (int) $backoff);
    }

    private function matchesFilters(array $job, array $filters): bool
    {
        if (isset($filters['id']) && ($job['id'] ?? null) !== $filters['id']) {
            return false;
        }

        if (isset($filters['job']) && ($job['job'] ?? null) !== $filters['job']) {
            return false;
        }

        return true;
    }

    private function record(string $type, array $payload): void
    {
        if (!class_exists('SparkInspector')) {
            return;
        }

        SparkInspector::recordQueue([
            'type' => $type,
            'job' => $payload['job'] ?? null,
            'queue' => $payload['queue'] ?? null,
            'data' => $payload['data'] ?? null,
            'attempts' => $payload['attempts'] ?? null,
            'tries' => $payload['tries'] ?? null,
            'timeout' => $payload['timeout'] ?? null,
            'delay' => $payload['delay'] ?? null,
            'error' => $payload['last_error'] ?? ($payload['error'] ?? null),
        ]);
    }

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
        if (!file_exists($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true) ?? [];
    }

    private function save(string $file, array $jobs): void
    {
        file_put_contents($file, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function timestamp(int $unixTime): string
    {
        return date('Y-m-d H:i:s', $unixTime);
    }
}
