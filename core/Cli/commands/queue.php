<?php

declare(strict_types=1);

function sparkQueueWork(array $args): void
{
    require_once SPARK_BASE . '/core/Queue.php';

    $queue   = 'default';
    $sleep   = 3;
    $maxJobs = 0; // 0 = unlimited
    $tries   = 3;

    foreach ($args as $arg) {
        if (str_starts_with($arg, '--queue='))    $queue   = substr($arg, 8);
        if (str_starts_with($arg, '--sleep='))    $sleep   = (int)substr($arg, 8);
        if (str_starts_with($arg, '--max-jobs=')) $maxJobs = (int)substr($arg, 11);
        if (str_starts_with($arg, '--tries='))    $tries   = (int)substr($arg, 8);
    }

    $q       = new Queue(SPARK_BASE);
    $count   = 0;
    echo "\n";
    out(color('  ⚡ Queue worker started', 'cyan') . color(" [{$queue}]", 'green') . color(' — Ctrl+C to stop.', 'dim'));
    echo "\n";

    while (true) {
        $report = $q->workOnce($queue, $tries);

        if ($report !== null) {
            $count++;

            $timestamp = color(date('H:i:s'), 'dim');
            $label = sparkQueueJobLabel($report['job'] ?? 'job');

            if (($report['status'] ?? null) === 'processed') {
                out('  ' . $timestamp . ' ' . color("Processed {$label}", 'green')
                    . color(" [{$report['id']}]", 'dim'));
            } elseif (($report['status'] ?? null) === 'released') {
                out('  ' . $timestamp . ' ' . color("Released {$label}", 'yellow')
                    . color(" [{$report['id']}] retry in {$report['delay']}s", 'dim'));
            } else {
                out('  ' . $timestamp . ' ' . color("Failed {$label}", 'red')
                    . color(" [{$report['id']}] {$report['error']}", 'dim'));
            }

            if ($maxJobs > 0 && $count >= $maxJobs) {
                success("Max jobs reached ({$maxJobs}). Exiting.");
                break;
            }
        } else {
            sleep($sleep);
        }
    }
}

function sparkQueueClear(array $args): void
{
    require_once SPARK_BASE . '/core/Queue.php';

    $queue = 'default';
    $filters = [];

    foreach ($args as $arg) {
        if ($arg === '--failed') {
            $queue = 'failed';
            continue;
        }

        if (str_starts_with($arg, '--queue=')) {
            $queue = substr($arg, 8);
            continue;
        }

        if (str_starts_with($arg, '--job=')) {
            $filters['job'] = substr($arg, 6);
            continue;
        }

        if (str_starts_with($arg, '--id=')) {
            $filters['id'] = substr($arg, 5);
            continue;
        }

        if (!str_starts_with($arg, '--')) {
            $queue = $arg;
        }
    }

    $removed = (new Queue(SPARK_BASE))->clear($queue, $filters);
    success("Queue [{$queue}] cleared ({$removed} job(s) removed).");
}

function sparkQueueList(): void
{
    require_once SPARK_BASE . '/core/Queue.php';
    $q = new Queue(SPARK_BASE);
    $queues = $q->queues();

    echo "\n";
    out('  ' . color(str_pad('Queue', 24), 'yellow')
        . color(str_pad('Ready', 10), 'yellow')
        . color(str_pad('Delayed', 12), 'yellow')
        . color('Total', 'yellow'));
    out('  ' . color(str_repeat('─', 54), 'dim'));

    if ($queues === []) {
        out('  ' . color('No queue files yet.', 'dim'));
        echo "\n";
        return;
    }

    foreach ($queues as $name) {
        $stats = $q->stats($name);
        out('  ' . color(str_pad($name, 24), 'green')
            . color(str_pad((string) $stats['ready'], 10), $stats['ready'] > 0 ? 'cyan' : 'dim')
            . color(str_pad((string) $stats['delayed'], 12), $stats['delayed'] > 0 ? 'yellow' : 'dim')
            . color((string) $stats['total'], $stats['total'] > 0 ? 'white' : 'dim'));
    }

    echo "\n";
}

function sparkQueueInspect(array $args): void
{
    require_once SPARK_BASE . '/core/Queue.php';

    $id = null;
    $queue = null;
    $asJson = false;

    foreach ($args as $arg) {
        if ($arg === '--json') {
            $asJson = true;
            continue;
        }

        if (str_starts_with($arg, '--queue=')) {
            $queue = substr($arg, 8);
            continue;
        }

        if (!str_starts_with($arg, '--') && $id === null) {
            $id = $arg;
        }
    }

    if ($id === null) {
        throw new RuntimeException('Usage: php spark queue:inspect <job-id> [--queue=name] [--json]');
    }

    $job = (new Queue(SPARK_BASE))->inspect($id, $queue);
    if ($job === null) {
        throw new RuntimeException("Queue job [{$id}] not found.");
    }

    if ($asJson) {
        echo json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo "\n";
    out('  ' . color('Queue', 'yellow') . ': ' . color($job['queue'], 'green'));
    out('  ' . color('Job', 'yellow') . ': ' . color(sparkQueueJobLabel($job['job']['job'] ?? ''), 'white'));
    out('  ' . color('ID', 'yellow') . ': ' . color($job['job']['id'] ?? '-', 'dim'));

    foreach ([
        'attempts' => 'Attempts',
        'tries' => 'Max tries',
        'timeout' => 'Timeout',
        'available_at' => 'Available at',
        'failed_at' => 'Failed at',
        'failure_reason' => 'Failure',
        'last_error' => 'Last error',
    ] as $key => $label) {
        if (!array_key_exists($key, $job['job']) || $job['job'][$key] === null || $job['job'][$key] === '') {
            continue;
        }

        $value = is_array($job['job'][$key])
            ? json_encode($job['job'][$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $job['job'][$key];

        out('  ' . color($label, 'yellow') . ': ' . color($value, 'dim'));
    }

    out('  ' . color('Backoff', 'yellow') . ': ' . color(
        is_array($job['job']['backoff'] ?? null)
            ? implode(', ', $job['job']['backoff'])
            : (string) ($job['job']['backoff'] ?? '0'),
        'dim'
    ));
    out('  ' . color('Data', 'yellow') . ': ' . color(
        json_encode($job['job']['data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'dim'
    ));
    echo "\n";
}

function sparkQueueRetry(array $args): void
{
    require_once SPARK_BASE . '/core/Queue.php';

    $id = null;
    $from = 'failed';
    $target = null;
    $all = false;

    foreach ($args as $arg) {
        if ($arg === '--all') {
            $all = true;
            continue;
        }

        if (str_starts_with($arg, '--from=')) {
            $from = substr($arg, 7);
            continue;
        }

        if (str_starts_with($arg, '--queue=')) {
            $target = substr($arg, 8);
            continue;
        }

        if (!str_starts_with($arg, '--') && $id === null) {
            $id = $arg;
        }
    }

    $queue = new Queue(SPARK_BASE);

    if ($all) {
        $count = $queue->retryAll($from, $target);
        success("Retried {$count} job(s) from [{$from}].");
        return;
    }

    if ($id === null) {
        throw new RuntimeException('Usage: php spark queue:retry <job-id> [--queue=name] [--from=failed] [--all]');
    }

    if (!$queue->retry($id, $from, $target)) {
        throw new RuntimeException("Queue job [{$id}] not found in [{$from}].");
    }

    success("Retried job [{$id}] from [{$from}].");
}

