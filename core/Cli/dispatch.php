<?php

declare(strict_types=1);

function sparkDispatch(array $args): int
{
    if ($args === [] || sparkIsInfoFlag($args[0])) {
        sparkHelp();
        return 0;
    }

    $commandInput = $args[0];
    $rest = array_slice($args, 1);

    if ($commandInput === 'help') {
        sparkHelp(sparkResolveHelpTarget($rest));
        return 0;
    }

    $commandInfo = sparkResolveCommandInfo($commandInput);
    $command = $commandInfo['name'] ?? $commandInput;

    if (sparkArgsContainInfoFlag($rest)) {
        sparkHelp($commandInput);
        return 0;
    }

    try {
        match (true) {
            $command === 'init' => sparkInit($rest),
            $command === 'new' => sparkNew($rest),
            $command === 'upgrade' => sparkUpgrade($rest),
            $command === 'starter:list' => sparkStarterList($rest),
            $command === 'help' => sparkHelp(sparkResolveHelpTarget($rest)),
            $command === 'serve' => sparkServe($rest),
            $command === 'about' => sparkAbout(),
            $command === 'version' => sparkVersion(),
            $command === 'benchmark' => sparkBenchmark($rest),
            $command === 'migrate' => sparkMigrate($rest),
            $command === 'migrate:rollback' => sparkMigrateRollback((int) ($rest[0] ?? 1)),
            $command === 'migrate:fresh' => sparkDbFresh($rest),
            $command === 'migrate:status' => sparkMigrateStatus(),
            $command === 'db:show' => sparkDbShow(),
            $command === 'db:table' => sparkDbTable($rest[0] ?? null),
            $command === 'db:wipe' => sparkDbWipe(),
            $command === 'seed' => sparkSeed($rest[0] ?? null),
            $command === 'queue:work' => sparkQueueWork($rest),
            $command === 'queue:clear' => sparkQueueClear($rest),
            $command === 'queue:list' => sparkQueueList(),
            $command === 'queue:inspect' => sparkQueueInspect($rest),
            $command === 'queue:retry' => sparkQueueRetry($rest),
            $command === 'cache:clear' => sparkCacheClear(),
            $command === 'views:cache' => sparkViewsCache(),
            $command === 'views:clear' => sparkViewsClear(),
            $command === 'routes:cache' => sparkRoutesCache(),
            $command === 'routes:clear' => sparkRoutesClear(),
            $command === 'routes:list' => sparkRoutesList(),
            $command === 'api:spec' => sparkApiSpec($rest),
            $command === 'inspector:clear' => sparkInspectorClear(),
            $command === 'inspector:status' => sparkInspectorStatus(),
            $command === 'ai:status' => sparkAiStatus($rest),
            $command === 'ai:smoke-test' => sparkAiSmokeTest($rest),
            $command === 'optimize' => sparkOptimize(),
            str_starts_with($command, 'make:') => sparkMake($command, $rest),
            default => sparkHelp(),
        };
    } catch (Throwable $e) {
        error($e->getMessage());
        return 1;
    }

    return 0;
}
