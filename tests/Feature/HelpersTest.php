<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_KEY'] = 'test-secret-key-for-encryption';
    }

    public function testEncryptAndDecryptRoundtrip(): void
    {
        $original = 'Hello, SparkPHP!';
        $encrypted = encrypt($original);

        $this->assertNotSame($original, $encrypted);
        $this->assertSame($original, decrypt($encrypted));
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $encrypted1 = encrypt('same-data');
        $encrypted2 = encrypt('same-data');

        $this->assertNotSame($encrypted1, $encrypted2); // different IVs
    }

    public function testDecryptReturnsFalseOnGarbage(): void
    {
        $this->assertFalse(decrypt('not-valid-base64!!!'));
    }

    public function testDecryptReturnsFalseOnTooShortData(): void
    {
        $this->assertFalse(decrypt(base64_encode('short')));
    }

    public function testHashPasswordAndVerify(): void
    {
        $hash = hash_password('secret123');

        $this->assertTrue(verify_password('secret123', $hash));
        $this->assertFalse(verify_password('wrong', $hash));
    }

    public function testVerifyIsAlias(): void
    {
        $hash = hash_password('test');
        $this->assertTrue(verify('test', $hash));
    }

    public function testEnvHelper(): void
    {
        $_ENV['TEST_KEY'] = 'test_value';
        $this->assertSame('test_value', env('TEST_KEY'));
        $this->assertSame('default', env('MISSING_KEY', 'default'));
        unset($_ENV['TEST_KEY']);
    }

    public function testUrlHelper(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $this->assertSame('http://localhost:8000/users', url('users'));
        $this->assertSame('http://localhost:8000/', url());
    }

    public function testAssetHelper(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $this->assertSame('http://localhost:8000/public/css/app.css', asset('css/app.css'));
    }

    public function testNowReturnsDateTimeImmutable(): void
    {
        $now = now();
        $this->assertInstanceOf(\DateTimeImmutable::class, $now);
    }
}
