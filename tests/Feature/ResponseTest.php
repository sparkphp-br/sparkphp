<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @header_remove();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        @header_remove();
        http_response_code(200);
        parent::tearDown();
    }

    public function testJsonFactoryBuildsResponseWithExpectedStatusAndHeader(): void
    {
        $response = Response::json(['ok' => true], 201);

        $status = $this->readPrivate($response, 'status');
        $headers = $this->readPrivate($response, 'headers');
        $body = $this->readPrivate($response, 'body');

        $this->assertSame(201, $status);
        $this->assertSame('application/json; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame('{"ok":true}', $body);
    }

    public function testResolveReturns404MarkupForNullGetResult(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(null, new FakeRequest('GET', false), new ErrorAwareFakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('error-errors/404', $output);
    }

    public function testResolveReturnsJsonForArrayWhenRequestAcceptsJson(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['status' => 'ok'], new FakeRequest('GET', true), new FakeView(), '/api/health');
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertSame('{"status":"ok"}', $output);
    }

    public function testResolveUses201ForPostJsonArrayResult(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['id' => 10], new FakeRequest('POST', true), new FakeView(), '/api/users');
        $output = ob_get_clean();

        $this->assertSame(201, http_response_code());
        $this->assertSame('{"id":10}', $output);
    }

    public function testResolveUses201ForPostHtmlArrayResult(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['name' => 'Spark'], new FakeRequest('POST', false), new FakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(201, http_response_code());
        $this->assertSame('<h1>ok</h1>', $output);
    }

    public function testResolveFallsBackToJsonWhenViewIsMissing(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['name' => 'Spark'], new FakeRequest('POST', false), new ThrowingFakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(201, http_response_code());
        $this->assertSame('{"name":"Spark"}', $output);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        $ref = new ReflectionClass($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($target);
    }
}

final class FakeRequest extends Request
{
    public function __construct(private string $fakeMethod, private bool $fakeAcceptsJson)
    {
    }

    public function method(): string
    {
        return strtoupper($this->fakeMethod);
    }

    public function acceptsJson(): bool
    {
        return $this->fakeAcceptsJson;
    }
}

class FakeView extends View
{
    public function __construct()
    {
    }

    public function render(string $name, array $data = []): string
    {
        return '<h1>ok</h1>';
    }
}

final class ThrowingFakeView extends FakeView
{
    public function render(string $name, array $data = []): string
    {
        throw new RuntimeException('View not found');
    }
}

final class ErrorAwareFakeView extends FakeView
{
    public function render(string $name, array $data = []): string
    {
        return "<h1>error-{$name}</h1>";
    }
}
