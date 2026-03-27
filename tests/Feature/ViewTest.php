<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['APP_ENV'] = 'dev';
        $_ENV['CACHE'] = 'file';

        $this->basePath = sys_get_temp_dir() . '/sparkphp-view-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/app/views/layouts', 0777, true);
        mkdir($this->basePath . '/storage/cache/views', 0777, true);
        mkdir($this->basePath . '/storage/cache/app', 0777, true);

        file_put_contents($this->basePath . '/app/views/layouts/main.spark', '@content');

        $app = new Bootstrap($this->basePath);
        $container = new Container();
        $container->singleton(Cache::class, fn() => new Cache($this->basePath));

        $ref = new ReflectionClass($app);
        $prop = $ref->getProperty('container');
        $prop->setAccessible(true);
        $prop->setValue($app, $container);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testCacheDirectiveCompilesAndRendersFragmentContent(): void
    {
        file_put_contents($this->basePath . '/app/views/index.spark', <<<'SPARK'
@cache('greeting', 60)
Hello {{ $name }}
@endcache
SPARK
        );

        $view = new View($this->basePath);
        $html = trim($view->render('index', ['name' => 'Spark']));

        $this->assertSame('Hello Spark', $html);
    }

    public function testCacheDirectiveReusesStoredFragmentAcrossRenders(): void
    {
        file_put_contents($this->basePath . '/app/views/index.spark', <<<'SPARK'
@cache('greeting', 60)
Hello {{ $name }}
@endcache
SPARK
        );

        $view = new View($this->basePath);

        $first = trim($view->render('index', ['name' => 'Spark']));
        $second = trim($view->render('index', ['name' => 'Nova']));

        $this->assertSame('Hello Spark', $first);
        $this->assertSame('Hello Spark', $second);
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
