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
        $_ENV['APP_URL'] = '';

        $this->basePath = sys_get_temp_dir() . '/sparkphp-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/app/routes', 0777, true);
        mkdir($this->basePath . '/storage/cache', 0777, true);

        $this->resetRouterState();
    }

    protected function tearDown(): void
    {
        $this->resetRouterState();
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

    public function testExactStaticRouteWinsOverDynamicSibling(): void
    {
        $this->writeRoute(
            'users/create.php',
            <<<'ROUTE'
<?php
get(fn() => ['screen' => 'create']);
ROUTE
        );

        $this->writeRoute(
            'users.[slug].php',
            <<<'ROUTE'
<?php
get(fn(string $slug) => ['slug' => $slug]);
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/users/create', 'GET');

        $this->assertIsArray($match);
        $this->assertSame('/users/create', $match['route']);
        $this->assertSame([], $match['params']);
        $this->assertSame(['screen' => 'create'], ($match['handler'])());
    }

    public function testPathAliasesRegisterNamedRoutesForUrlGeneration(): void
    {
        mkdir($this->basePath . '/app/routes/docs', 0777, true);

        $this->writeRoute(
            'docs/index.php',
            <<<'ROUTE'
<?php
path('/documents')->name('docs.index');
get(fn() => ['index' => true]);
ROUTE
        );

        $this->writeRoute(
            'docs.[slug].php',
            <<<'ROUTE'
<?php
path('/documents/:slug')->name('docs.show');
get(fn(string $slug) => ['slug' => $slug]);
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/documents/getting-started', 'GET');

        $this->assertIsArray($match);
        $this->assertSame('/documents/:slug', $match['route']);
        $this->assertSame('getting-started', $match['params']['slug']);
        $this->assertSame('/documents', route('docs.index'));
        $this->assertSame('/documents/getting-started', route('docs.show', ['slug' => 'getting-started']));
        $this->assertSame([
            'docs.index' => '/documents',
            'docs.show' => '/documents/:slug',
        ], Router::getNamedRoutes());
    }

    public function testAppliesGlobalAndDirectoryMiddlewareFilesBeforeBracketAndInlineGuards(): void
    {
        $this->writeRoute(
            '_middleware.php',
            <<<'ROUTE'
<?php
return ['global'];
ROUTE
        );

        $this->writeRoute(
            'api/_middleware.php',
            <<<'ROUTE'
<?php
return 'api';
ROUTE
        );

        mkdir($this->basePath . '/app/routes/api/[auth]', 0777, true);

        $this->writeRoute(
            'api/[auth]/reports.php',
            <<<'ROUTE'
<?php
get(fn() => ['ok' => true])->guard('csrf');
ROUTE
        );

        $router = new Router($this->basePath);
        $match  = $router->resolve('/api/reports', 'GET');

        $this->assertIsArray($match);
        $this->assertSame(['global', 'api', 'auth', 'csrf'], $match['middlewares']);
    }

    public function testMiddlewareFilesAreIgnoredAsRoutes(): void
    {
        $this->writeRoute(
            '_middleware.php',
            <<<'ROUTE'
<?php
return ['global'];
ROUTE
        );

        $this->writeRoute(
            'admin/_middleware.php',
            <<<'ROUTE'
<?php
return ['admin'];
ROUTE
        );

        $this->writeRoute(
            'index.php',
            <<<'ROUTE'
<?php
get(fn() => ['ok' => true]);
ROUTE
        );

        $this->writeRoute(
            'admin/panel.php',
            <<<'ROUTE'
<?php
get(fn() => ['panel' => true]);
ROUTE
        );

        $router = new Router($this->basePath);

        $this->assertCount(2, $router->list());
        $this->assertNull($router->resolve('/_middleware', 'GET'));
        $this->assertNull($router->resolve('/admin/_middleware', 'GET'));
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

    private function resetRouterState(): void
    {
        Router::$_collected = [];
        Router::$_meta = [];
        Router::$_path = null;
        Router::$_routeName = null;

        $ref = new ReflectionClass(Router::class);
        $prop = $ref->getProperty('namedRoutes');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
