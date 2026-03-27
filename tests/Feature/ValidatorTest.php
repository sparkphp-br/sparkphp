<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $_SERVER['CONTENT_TYPE'] = 'text/html';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function testRequiredFieldPasses(): void
    {
        $v = Validator::make(['name' => 'John'], ['name' => 'required']);
        $this->assertFalse($v->fails());
    }

    public function testRequiredFieldFails(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function testEmailValidation(): void
    {
        $v = Validator::make(['email' => 'user@example.com'], ['email' => 'email']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['email' => 'not-an-email'], ['email' => 'email']);
        $this->assertTrue($v2->fails());
    }

    public function testMinMaxStringLength(): void
    {
        $v = Validator::make(['name' => 'Jo'], ['name' => 'min:3']);
        $this->assertTrue($v->fails());

        $v2 = Validator::make(['name' => 'John'], ['name' => 'min:3|max:10']);
        $this->assertFalse($v2->fails());

        $v3 = Validator::make(['name' => 'A very long name that exceeds the limit'], ['name' => 'max:10']);
        $this->assertTrue($v3->fails());
    }

    public function testMinMaxNumeric(): void
    {
        $v = Validator::make(['age' => '25'], ['age' => 'numeric|min:18|max:99']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['age' => '10'], ['age' => 'numeric|min:18']);
        $this->assertTrue($v2->fails());
    }

    public function testOptionalFieldSkipsWhenEmpty(): void
    {
        $v = Validator::make(['bio' => ''], ['bio' => 'optional|min:10']);
        $this->assertFalse($v->fails());
    }

    public function testOptionalFieldValidatesWhenPresent(): void
    {
        $v = Validator::make(['bio' => 'Hi'], ['bio' => 'optional|min:10']);
        $this->assertTrue($v->fails());
    }

    public function testInRule(): void
    {
        $v = Validator::make(['status' => 'active'], ['status' => 'in:active,inactive']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['status' => 'deleted'], ['status' => 'in:active,inactive']);
        $this->assertTrue($v2->fails());
    }

    public function testBetweenRule(): void
    {
        $v = Validator::make(['score' => '50'], ['score' => 'between:1,100']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['score' => '150'], ['score' => 'between:1,100']);
        $this->assertTrue($v2->fails());
    }

    public function testConfirmedRule(): void
    {
        $data = ['password' => 'secret', 'password_confirmation' => 'secret'];
        $v = Validator::make($data, ['password' => 'confirmed']);
        $this->assertFalse($v->fails());

        $data2 = ['password' => 'secret', 'password_confirmation' => 'different'];
        $v2 = Validator::make($data2, ['password' => 'confirmed']);
        $this->assertTrue($v2->fails());
    }

    public function testDateRule(): void
    {
        $v = Validator::make(['date' => '2025-01-15'], ['date' => 'date']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['date' => 'not-a-date'], ['date' => 'date']);
        $this->assertTrue($v2->fails());
    }

    public function testUrlRule(): void
    {
        $v = Validator::make(['site' => 'https://example.com'], ['site' => 'url']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['site' => 'not a url'], ['site' => 'url']);
        $this->assertTrue($v2->fails());
    }

    public function testBoolRule(): void
    {
        $v = Validator::make(['active' => '1'], ['active' => 'bool']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['active' => 'yes'], ['active' => 'bool']);
        $this->assertTrue($v2->fails());
    }

    public function testRegexRule(): void
    {
        $v = Validator::make(['code' => 'ABC123'], ['code' => 'regex:/^[A-Z]{3}\d{3}$/']);
        $this->assertFalse($v->fails());

        $v2 = Validator::make(['code' => 'abc'], ['code' => 'regex:/^[A-Z]{3}\d{3}$/']);
        $this->assertTrue($v2->fails());
    }

    public function testFailsDoesNotDuplicateErrors(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        $v->fails();
        $v->fails(); // call twice
        $errors = $v->errors();
        $this->assertCount(1, $errors);
    }

    public function testErrorMessagesAreInPortuguese(): void
    {
        $v = Validator::make(['email' => 'bad'], ['email' => 'email']);
        $v->fails();
        $errors = $v->errors();
        $this->assertStringContainsString('e-mail válido', $errors['email']);
    }
}
