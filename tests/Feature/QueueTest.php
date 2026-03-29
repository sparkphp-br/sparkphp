<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

#[OnQueue('attribute-mail')]
#[Tries(4)]
#[Backoff([2, 4])]
#[Timeout(0.02)]
#[FailOnTimeout]
class AttributeConfiguredQueueJob
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
    }
}

class PropertyConfiguredQueueJob
{
    public string $queue = 'property-mail';
    public int $tries = 2;
    public array $backoff = [0];

    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
    }
}

class AlwaysFailingQueueJob
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        throw new RuntimeException((string) ($this->data['message'] ?? 'boom'));
    }
}

class SlowFailOnTimeoutQueueJob
{
    public float $timeout = 0.01;
    public bool $failOnTimeout = true;

    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        usleep(50_000);
    }
}

class SlowRetryingTimeoutQueueJob
{
    public float $timeout = 0.01;
    public bool $failOnTimeout = false;
    public int $tries = 2;
    public array $backoff = [0];

    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
        usleep(50_000);
    }
}

final class QueueTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::flushRoutes();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-queue-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/app/jobs', 0777, true);
        mkdir($this->basePath . '/storage/queue', 0777, true);
    }

    protected function tearDown(): void
    {
        Queue::flushRoutes();
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testQueueResolvesAttributesManifestAndRuntimeRoutesWithClearPrecedence(): void
    {
        file_put_contents($this->basePath . '/app/jobs/_queue.php', <<<'PHP'
<?php

return [
    'defaults' => [
        'tries' => 3,
        'backoff' => [60, 120, 300],
    ],
    'routes' => [
        AttributeConfiguredQueueJob::class => [
            'queue' => 'manifest-mail',
            'tries' => 6,
            'backoff' => [9, 15],
            'timeout' => 0.5,
            'fail_on_timeout' => false,
        ],
    ],
];
PHP
        );

        Queue::route(AttributeConfiguredQueueJob::class, queue: 'runtime-mail', tries: 7);

        $queue = new Queue($this->basePath);

        $attributeId = $queue->push(AttributeConfiguredQueueJob::class, ['user_id' => 1]);
        $propertyId = $queue->push(PropertyConfiguredQueueJob::class, ['user_id' => 2]);

        $attributeJob = $queue->inspect($attributeId, 'runtime-mail');
        $propertyJob = $queue->inspect($propertyId, 'property-mail');

        $this->assertNotNull($attributeJob);
        $this->assertSame('runtime-mail', $attributeJob['queue']);
        $this->assertSame(7, $attributeJob['job']['tries']);
        $this->assertSame([9, 15], $attributeJob['job']['backoff']);
        $this->assertSame(0.5, $attributeJob['job']['timeout']);
        $this->assertFalse($attributeJob['job']['fail_on_timeout']);

        $this->assertNotNull($propertyJob);
        $this->assertSame('property-mail', $propertyJob['queue']);
        $this->assertSame(2, $propertyJob['job']['tries']);
        $this->assertSame([0], $propertyJob['job']['backoff']);
    }

    public function testFailingJobsReleaseRetryAndMoveToFailedQueueWithRichMetadata(): void
    {
        Queue::route(AlwaysFailingQueueJob::class, tries: 2, backoff: 0);

        $queue = new Queue($this->basePath);
        $id = $queue->push(AlwaysFailingQueueJob::class, ['message' => 'queue failed']);

        $released = $queue->workOnce();

        $this->assertSame('released', $released['status']);
        $this->assertSame(1, $released['attempts']);
        $this->assertSame(1, $queue->size('default'));
        $this->assertSame(0, $queue->size('failed'));

        $failed = $queue->workOnce();
        $failedJob = $queue->inspect($id, 'failed');

        $this->assertSame('failed', $failed['status']);
        $this->assertSame('exception', $failed['failure_reason']);
        $this->assertSame(0, $queue->size('default'));
        $this->assertSame(1, $queue->size('failed'));
        $this->assertNotNull($failedJob);
        $this->assertSame('queue failed', $failedJob['job']['last_error']);
        $this->assertSame(2, $failedJob['job']['attempts']);
        $this->assertSame('exception', $failedJob['job']['failure_reason']);

        $this->assertTrue($queue->retry($id));
        $this->assertSame(0, $queue->size('failed'));
        $this->assertSame(1, $queue->size('default'));

        $removed = $queue->clear('default', ['id' => $id]);

        $this->assertSame(1, $removed);
        $this->assertSame(0, $queue->size('default'));
    }

    public function testTimeoutCanEitherFailImmediatelyOrRetryDependingOnJobConfig(): void
    {
        $queue = new Queue($this->basePath);

        $failFastId = $queue->push(SlowFailOnTimeoutQueueJob::class);
        $failFast = $queue->workOnce();
        $failFastJob = $queue->inspect($failFastId, 'failed');

        $this->assertSame('failed', $failFast['status']);
        $this->assertSame('timeout', $failFast['failure_reason']);
        $this->assertNotNull($failFastJob);
        $this->assertSame('timeout', $failFastJob['job']['failure_reason']);

        $retryId = $queue->push(SlowRetryingTimeoutQueueJob::class);

        $released = $queue->workOnce();
        $failedAfterRetry = $queue->workOnce();
        $retryJob = $queue->inspect($retryId, 'failed');

        $this->assertSame('released', $released['status']);
        $this->assertSame('timeout', $released['failure_reason']);
        $this->assertSame('failed', $failedAfterRetry['status']);
        $this->assertSame('timeout', $failedAfterRetry['failure_reason']);
        $this->assertNotNull($retryJob);
        $this->assertSame(2, $retryJob['job']['attempts']);
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
