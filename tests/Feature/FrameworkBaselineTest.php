<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrameworkBaselineTest extends TestCase
{
    public function testRuntimeMatchesMinimumSupportedPhpVersion(): void
    {
        $this->assertSame(80300, Bootstrap::MIN_PHP_VERSION_ID);
        $this->assertSame('8.3', Bootstrap::MIN_PHP_VERSION);
        $this->assertGreaterThanOrEqual(Bootstrap::MIN_PHP_VERSION_ID, PHP_VERSION_ID);
    }

    public function testComposerRequiresPhp83OrNewer(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame('>=' . Bootstrap::MIN_PHP_VERSION, $composer['require']['php'] ?? null);
    }
}
