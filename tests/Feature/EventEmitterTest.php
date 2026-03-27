<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EventEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear all listeners between tests
        EventEmitter::off('test.event');
        EventEmitter::off('test.cancel');
    }

    public function testDispatchCallsRegisteredListener(): void
    {
        $called = false;
        EventEmitter::on('test.event', function ($data) use (&$called) {
            $called = true;
            return true;
        });

        EventEmitter::dispatch('test.event', ['key' => 'value']);

        $this->assertTrue($called);
    }

    public function testDispatchPassesDataToListener(): void
    {
        $received = null;
        EventEmitter::on('test.event', function ($data) use (&$received) {
            $received = $data;
        });

        EventEmitter::dispatch('test.event', 'hello');

        $this->assertSame('hello', $received);
    }

    public function testListenerCanCancelEvent(): void
    {
        EventEmitter::on('test.cancel', fn() => false);

        $result = EventEmitter::dispatch('test.cancel');

        $this->assertFalse($result);
    }

    public function testMultipleListenersAllCalled(): void
    {
        $calls = [];
        EventEmitter::on('test.event', function () use (&$calls) { $calls[] = 'a'; });
        EventEmitter::on('test.event', function () use (&$calls) { $calls[] = 'b'; });

        EventEmitter::dispatch('test.event');

        $this->assertSame(['a', 'b'], $calls);
    }

    public function testOffRemovesAllListeners(): void
    {
        $called = false;
        EventEmitter::on('test.event', function () use (&$called) { $called = true; });
        EventEmitter::off('test.event');

        EventEmitter::dispatch('test.event');

        $this->assertFalse($called);
    }

    public function testOffRemovesSpecificListener(): void
    {
        $calls = [];
        $listenerA = function () use (&$calls) { $calls[] = 'a'; };
        $listenerB = function () use (&$calls) { $calls[] = 'b'; };

        EventEmitter::on('test.event', $listenerA);
        EventEmitter::on('test.event', $listenerB);
        EventEmitter::off('test.event', $listenerA);

        EventEmitter::dispatch('test.event');

        $this->assertSame(['b'], $calls);
    }

    public function testDispatchFileBasedEvent(): void
    {
        $basePath = sys_get_temp_dir() . '/sparkphp-events-' . bin2hex(random_bytes(4));
        mkdir($basePath . '/app/events', 0777, true);

        file_put_contents($basePath . '/app/events/user.created.php', '<?php return "event-ran";');

        EventEmitter::setBasePath($basePath);
        $result = EventEmitter::dispatch('user.created', ['user_id' => 1]);

        $this->assertTrue($result);

        // Cleanup
        unlink($basePath . '/app/events/user.created.php');
        rmdir($basePath . '/app/events');
        rmdir($basePath . '/app');
        rmdir($basePath);

        EventEmitter::setBasePath('');
    }
}
