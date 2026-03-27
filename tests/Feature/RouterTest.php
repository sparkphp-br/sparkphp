<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['APP_ENV'] = 'dev';

        $this->basePath = sys_get_temp_dir() . '/sparkphp-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/app/routes', 0777, true);
        mkdir($this->basePath . '/storage/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testResolvesDynamicParamByName(): void
    {
        $this->writeRoute(
            'users.[id].php',
            <<<'ROUTE'
<?php
get(fn(string $id) => ['id' => $id]);
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/users/42', 'GET');

        $this->assertIsArray($match);
        $this->assertSame('42', $match['params']['id']);
        $this->assertSame('42', $match['params'][0]);
        $this->assertSame('/users/:id', $match['route']);
    }

    public function testReturns405WithAllowedMethodsWhenPathMatchesButVerbDoesNot(): void
    {
        $this->writeRoute(
            'users.[id].php',
            <<<'ROUTE'
<?php
get(fn(string $id) => ['id' => $id]);
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/users/42', 'DELETE');

        $this->assertIsArray($match);
        $this->assertSame(405, $match['status']);
        $this->assertContains('GET', $match['allowed']);
    }

    public function testMergesInlineGuardMiddlewareWithDirectoryMiddleware(): void
    {
        mkdir($this->basePath . '/app/routes/api/[auth]', 0777, true);

        $this->writeRoute(
            'api/[auth]/users.php',
            <<<'ROUTE'
<?php
get(fn() => ['ok' => true])->guard('throttle:10', 'csrf');
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/api/users', 'GET');

        $this->assertIsArray($match);
        $this->assertSame(['auth', 'throttle:10', 'csrf'], $match['middlewares']);
    }

    public function testResolvesRootIndexRoute(): void
    {
        $this->writeRoute(
            'index.php',
            <<<'ROUTE'
<?php
get(fn() => ['ok' => true]);
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/', 'GET');

        $this->assertIsArray($match);
        $this->assertSame('/', $match['route']);
        $this->assertSame([], $match['params']);
    }

    private function writeRoute(string $relativePath, string $content): void
    {
        $path = $this->basePath . '/app/routes/' . $relativePath;
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $content . PHP_EOL);
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
