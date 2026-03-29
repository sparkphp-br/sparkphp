<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    private string $basePath;
    private array $envBackup = [];
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = $_ENV;
        $_ENV['APP_KEY'] = 'test-secret-key-for-encryption';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['CACHE'] = 'memory';

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_SESSION = [];

        $this->basePath = sys_get_temp_dir() . '/sparkphp-helpers-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/app/config', 0777, true);
        mkdir($this->basePath . '/storage/cache/app', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
        mkdir($this->basePath . '/storage/sessions', 0777, true);
        mkdir($this->basePath . '/public', 0777, true);

        file_put_contents($this->basePath . '/app/config/app.php', <<<'PHP'
<?php

return [
    'name' => 'Helper Test App',
];
PHP
        );

        $app = new Bootstrap($this->basePath);
        $container = new Container();
        $container->singleton(Cache::class, fn() => new Cache($this->basePath));
        $container->singleton(Logger::class, fn() => new Logger($this->basePath));
        $container->singleton(Queue::class, fn() => new Queue($this->basePath));
        $container->singleton(Session::class, fn() => new Session($this->basePath));
        $container->singleton(Request::class, fn() => new Request());

        $ref = new ReflectionClass($app);
        $prop = $ref->getProperty('container');
        $prop->setAccessible(true);
        $prop->setValue($app, $container);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SESSION = $this->sessionBackup;

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function testEncryptAndDecryptRoundtrip(): void
    {
        $original = 'Hello, SparkPHP!';
        $encrypted = encrypt($original);

        $this->assertNotSame($original, $encrypted);
        $this->assertSame($original, decrypt($encrypted));
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $encrypted1 = encrypt('same-data');
        $encrypted2 = encrypt('same-data');

        $this->assertNotSame($encrypted1, $encrypted2); // different IVs
    }

    public function testDecryptReturnsFalseOnGarbage(): void
    {
        $this->assertFalse(decrypt('not-valid-base64!!!'));
    }

    public function testDecryptReturnsFalseOnTooShortData(): void
    {
        $this->assertFalse(decrypt(base64_encode('short')));
    }

    public function testHashPasswordAndVerify(): void
    {
        $hash = hash_password('secret123');

        $this->assertTrue(verify_password('secret123', $hash));
        $this->assertFalse(verify_password('wrong', $hash));
    }

    public function testVerifyIsAlias(): void
    {
        $hash = hash_password('test');
        $this->assertTrue(verify('test', $hash));
    }

    public function testEnvHelper(): void
    {
        $_ENV['TEST_KEY'] = 'test_value';
        $this->assertSame('test_value', env('TEST_KEY'));
        $this->assertSame('default', env('MISSING_KEY', 'default'));
        unset($_ENV['TEST_KEY']);
    }

    public function testUrlHelper(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $this->assertSame('http://localhost:8000/users', url('users'));
        $this->assertSame('http://localhost:8000/', url());
    }

    public function testAssetHelper(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $this->assertSame('http://localhost:8000/public/css/app.css', asset('css/app.css'));
    }

    public function testNowReturnsDateTimeImmutable(): void
    {
        $now = now();
        $this->assertInstanceOf(\DateTimeImmutable::class, $now);
    }

    public function testPathHelpersResolveFromCurrentApplicationBasePath(): void
    {
        $this->assertSame($this->basePath, base_path());
        $this->assertSame($this->basePath . '/app/models', app_path('models'));
        $this->assertSame($this->basePath . '/storage/cache', storage_path('cache'));
        $this->assertSame($this->basePath . '/public/css/app.css', public_path('css/app.css'));
    }

    public function testRequestAndIpHelpersProxyCurrentRequestObject(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';
        $_ENV['TRUSTED_PROXIES'] = '127.0.0.1';

        $request = request();

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('203.0.113.10', ip());
    }

    public function testFlashHelperWritesAndReadsFlashData(): void
    {
        flash('success', 'Saved');

        $_SESSION['flash'] = $_SESSION['flash_new'] ?? [];
        $_SESSION['flash_new'] = [];

        $this->assertSame('Saved', flash('success'));
    }

    public function testCacheRememberAndCacheFlushHelpersDelegateToCacheStore(): void
    {
        $calls = 0;

        $first = cache_remember('helpers:key', 60, function () use (&$calls) {
            $calls++;
            return 'cached-value';
        });

        $second = cache_remember('helpers:key', 60, function () use (&$calls) {
            $calls++;
            return 'other-value';
        });

        $this->assertSame('cached-value', $first);
        $this->assertSame('cached-value', $second);
        $this->assertSame(1, $calls);

        cache_flush();

        $this->assertNull(cache('helpers:key'));
    }

    public function testEventHelperAliasesEmit(): void
    {
        EventEmitter::off('helpers.event');

        $received = null;
        EventEmitter::on('helpers.event', function ($payload) use (&$received) {
            $received = $payload;
        });

        $this->assertTrue(event('helpers.event', ['ok' => true]));
        $this->assertSame(['ok' => true], $received);

        EventEmitter::off('helpers.event');
    }

    public function testDispatchRespectsQueueManifestRoutingWhenQueueNameIsOmitted(): void
    {
        $_ENV['QUEUE'] = 'file';
        mkdir($this->basePath . '/app/jobs', 0777, true);

        file_put_contents($this->basePath . '/app/jobs/_queue.php', <<<'PHP'
<?php

return [
    'routes' => [
        HelperQueuedJob::class => [
            'queue' => 'emails',
            'tries' => 5,
        ],
    ],
];
PHP
        );

        dispatch(HelperQueuedJob::class, ['ok' => true]);

        $this->assertCount(1, queue()->jobs('emails'));
        $this->assertSame(0, queue()->size('default'));
        $this->assertSame(5, queue()->jobs('emails')[0]['tries']);
    }

    public function testUuidHelperGeneratesV4Uuid(): void
    {
        $uuid = uuid();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
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

class HelperQueuedJob
{
    public function __construct(private mixed $data = null) {}

    public function handle(): void
    {
    }
}
