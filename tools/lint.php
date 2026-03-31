<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$excludes = [
    $basePath . '/vendor',
    $basePath . '/storage',
    $basePath . '/.git',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
);

$errors = [];
$files = [$basePath . '/spark'];

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $skip = false;

    foreach ($excludes as $prefix) {
        if (str_starts_with($path, $prefix . DIRECTORY_SEPARATOR) || $path === $prefix) {
            $skip = true;
            break;
        }
    }

    if ($skip) {
        continue;
    }

    $files[] = $path;
}

sort($files);

foreach ($files as $path) {
    $output = [];
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $status);

    if ($status !== 0) {
        $errors[] = implode(PHP_EOL, $output);
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL . PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Lint OK for " . count($files) . " PHP files." . PHP_EOL);
