<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;

        parent::tearDown();
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton(ExampleService::class, fn() => new ExampleService('one'));

        $first = $container->make(ExampleService::class);
        $second = $container->make(ExampleService::class);

        $this->assertSame($first, $second);
    }

    public function testBindReturnsNewInstanceEachCall(): void
    {
        $container = new Container();
        $container->bind(ExampleService::class, fn() => new ExampleService('new'));

        $first = $container->make(ExampleService::class);
        $second = $container->make(ExampleService::class);

        $this->assertNotSame($first, $second);
    }

    public function testBuildAutowiresClassDependencies(): void
    {
        $container = new Container();
        $container->singleton(ExampleService::class, fn() => new ExampleService('wired'));

        $consumer = $container->make(ConsumerService::class);

        $this->assertInstanceOf(ConsumerService::class, $consumer);
        $this->assertSame('wired', $consumer->service->name);
    }

    public function testCallResolvesPrimitiveFromRequestInput(): void
    {
        $_GET['page'] = '5';

        $container = new Container();

        $result = $container->call(fn(int $page) => $page + 1);

        $this->assertSame(6, $result);
    }

    public function testCallUsesNamedExtrasForRouteParams(): void
    {
        $container = new Container();

        $result = $container->call(fn(string $id) => 'user-' . $id, ['id' => '42']);

        $this->assertSame('user-42', $result);
    }

    public function testMakeThrowsForUnresolvableAbstract(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $container->make('DefinitelyMissingClass');
    }
}

final class ExampleService
{
    public function __construct(public string $name)
    {
    }
}

final class ConsumerService
{
    public function __construct(public ExampleService $service)
    {
    }
}
