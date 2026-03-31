<?php

declare(strict_types=1);

function sparkQueueJobLabel(string $job): string
{
    if (!str_contains($job, '\\')) {
        return $job;
    }

    $parts = explode('\\', $job);
    return end($parts) ?: $job;
}

function sparkNextMigrationPrefix(): string
{
    return sparkMigrationTimestamp();
}

function sparkMigrationTimestamp(): string
{
    $dir = SPARK_BASE . '/database/migrations';
    $timestamp = date('YmdHis');

    while (is_dir($dir) && (glob($dir . '/' . $timestamp . '_*.php') ?: []) !== []) {
        $date = DateTimeImmutable::createFromFormat('YmdHis', $timestamp) ?: new DateTimeImmutable();
        $timestamp = $date->modify('+1 second')->format('YmdHis');
    }

    return $timestamp;
}

function sparkStudly(string $value): string
{
    $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value;
    $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? $value;
    $value = ucwords(strtolower(trim($value)));
    return str_replace(' ', '', $value);
}

function sparkSnake(string $value): string
{
    $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;
    $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? $value;
    $value = strtolower(trim($value, '_'));
    return preg_replace('/_+/', '_', $value) ?? $value;
}

function sparkWrapIdentifier(string $driver, string $value): string
{
    return match ($driver) {
        'mysql' => '`' . str_replace('`', '``', $value) . '`',
        'pgsql', 'sqlite' => '"' . str_replace('"', '""', $value) . '"',
        default => $value,
    };
}

function sparkFormatBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return number_format($value, $power === 0 ? 0 : 2) . ' ' . $units[$power];
}

function sparkFormatDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0m';
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }

    return $parts !== [] ? implode(' ', $parts) : '0m';
}

function sparkResolvePath(string $path, string $basePath): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return rtrim($basePath, '/\\');
    }

    if ($trimmed[0] === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $trimmed) === 1) {
        return rtrim($trimmed, '/\\');
    }

    return rtrim($basePath, '/\\') . '/' . ltrim($trimmed, '/\\');
}

function sparkPrintAuditList(string $title, array $items): void
{
    echo "\n";
    out('  ' . color($title, 'yellow'));

    if ($items === []) {
        out('  ' . color('• ', 'dim') . color('none', 'green'));
        return;
    }

    foreach ($items as $item) {
        out('  ' . color('• ', 'dim') . color((string) $item, 'white'));
    }
}

