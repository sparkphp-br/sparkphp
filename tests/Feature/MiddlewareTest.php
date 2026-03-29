<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-mw-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/app/middleware', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testRunReturnsNullWhenAllMiddlewarePass(): void
    {
        $this->writeMiddleware('pass', <<<'CODE'
<?php
return null;
CODE
        );

        $pipeline = new Middleware($this->basePath, ['pass']);

        $this->assertNull($pipeline->run());
    }

    public function testRunReturnsResponseWhenMiddlewareBlocks(): void
    {
        $this->writeMiddleware('block', <<<'CODE'
<?php
return Response::json(['blocked' => true], 401);
CODE
        );

        $pipeline = new Middleware($this->basePath, ['block']);
        $result = $pipeline->run();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->readPrivate($result, 'status'));
    }

    public function testRunReturnsStringWhenMiddlewareBlocksWithHtmlContent(): void
    {
        $this->writeMiddleware('html_block', <<<'CODE'
<?php
return '<h1>blocked</h1>';
CODE
        );

        $pipeline = new Middleware($this->basePath, ['html_block']);
        $result = $pipeline->run();

        $this->assertSame('<h1>blocked</h1>', $result);
    }

    public function testRunReturnsArrayWhenMiddlewareBlocksWithStructuredPayload(): void
    {
        $this->writeMiddleware('json_block', <<<'CODE'
<?php
return ['error' => 'blocked'];
CODE
        );

        $pipeline = new Middleware($this->basePath, ['json_block']);
        $result = $pipeline->run();

        $this->assertSame(['error' => 'blocked'], $result);
    }

    public function testRunSupportsRedirectHelperReturnContract(): void
    {
        $this->writeMiddleware('auth', <<<'CODE'
<?php
return redirect('/login');
CODE
        );

        $pipeline = new Middleware($this->basePath, ['auth']);
        $result = $pipeline->run();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(302, $this->readPrivate($result, 'status'));
        $this->assertSame('/login', $this->readPrivate($result, 'headers')['Location']);
    }

    public function testRunPassesParsedParamsToMiddlewareFileScope(): void
    {
        $this->writeMiddleware('throttle', <<<'CODE'
<?php
if (($params[0] ?? null) === '10') {
    return Response::json(['limit' => 10], 429);
}
return null;
CODE
        );

        $pipeline = new Middleware($this->basePath, ['throttle:10']);
        $result = $pipeline->run();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(429, $this->readPrivate($result, 'status'));
    }

    public function testRunThrowsWhenMiddlewareFileIsMissing(): void
    {
        $pipeline = new Middleware($this->basePath, ['missing']);

        $this->expectException(RuntimeException::class);
        $pipeline->run();
    }

    private function writeMiddleware(string $name, string $content): void
    {
        $path = $this->basePath . '/app/middleware/' . $name . '.php';
        file_put_contents($path, $content . PHP_EOL);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        $ref = new ReflectionClass($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($target);
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
