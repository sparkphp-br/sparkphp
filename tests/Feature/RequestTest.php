<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $filesBackup = [];
    private array $cookieBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->filesBackup = $_FILES;
        $this->cookieBackup = $_COOKIE;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_FILES = $this->filesBackup;
        $_COOKIE = $this->cookieBackup;

        parent::tearDown();
    }

    public function testInputMergesGetAndPost(): void
    {
        $_GET = ['page' => '2'];
        $_POST = ['name' => 'Spark'];

        $request = new Request();

        $this->assertSame('Spark', $request->input('name'));
        $this->assertSame('2', $request->input('page'));
        $this->assertSame(['name' => 'Spark'], $request->only(['name']));
        $this->assertSame(['page' => '2'], $request->except(['name']));
    }

    public function testMethodOverrideFromPostTakesEffect(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'PATCH';

        $request = new Request();

        $this->assertSame('PATCH', $request->method());
        $this->assertTrue($request->isPatch());
    }

    public function testPathStripsQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/users/42?with=posts';

        $request = new Request();

        $this->assertSame('/users/42', $request->path());
    }

    public function testAcceptsJsonWhenAjaxHeaderIsPresent(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $request = new Request();

        $this->assertTrue($request->isAjax());
        $this->assertTrue($request->acceptsJson());
    }

    public function testIpUsesForwardedAddressPriority(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.10.10.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = new Request();

        $this->assertSame('10.10.10.1', $request->ip());
    }
}
