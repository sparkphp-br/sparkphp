<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $basePath;
    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['CACHE'] = 'memory';
        $this->basePath = sys_get_temp_dir() . '/sparkphp-cache-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/cache/app', 0777, true);
        $this->cache = new Cache($this->basePath);
        $this->cache->flush();
    }

    protected function tearDown(): void
    {
        $this->cache->flush();
        @rmdir($this->basePath . '/storage/cache/app');
        @rmdir($this->basePath . '/storage/cache');
        @rmdir($this->basePath . '/storage');
        @rmdir($this->basePath);
        parent::tearDown();
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    public function testForgetRemovesKey(): void
    {
        $this->cache->set('key', 'value');
        $this->cache->forget('key');
        $this->assertNull($this->cache->get('key'));
    }

    public function testHas(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
        $this->assertFalse($this->cache->has('missing'));
    }

    public function testIncrement(): void
    {
        $this->cache->set('counter', 5);
        $result = $this->cache->increment('counter', 3);
        $this->assertSame(8, $result);
    }

    public function testDecrement(): void
    {
        $this->cache->set('counter', 10);
        $result = $this->cache->decrement('counter', 4);
        $this->assertSame(6, $result);
    }

    public function testIncrementFromZeroWhenKeyMissing(): void
    {
        $result = $this->cache->increment('newkey');
        $this->assertSame(1, $result);
    }

    public function testRemember(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return 'computed';
        };

        $first = $this->cache->remember('key', 3600, $callback);
        $second = $this->cache->remember('key', 3600, $callback);

        $this->assertSame('computed', $first);
        $this->assertSame('computed', $second);
        $this->assertSame(1, $callCount); // callback called only once
    }

    public function testFlush(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->flush();

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
    }

    public function testStoresArraysAndObjects(): void
    {
        $this->cache->set('arr', ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $this->cache->get('arr'));
    }
}
