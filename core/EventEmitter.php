<?php

class EventEmitter
{
    private static string $basePath = '';
    private static array $listeners = [];

    public static function setBasePath(string $path): void
    {
        static::$basePath = $path;
    }

    // ─────────────────────────────────────────────
    // Dispatch
    // ─────────────────────────────────────────────

    /**
     * Dispatch an event.
     * Returns false if a "before" event (*.creating / *.updating) cancels the action.
     */
    public static function dispatch(string $event, mixed $data = null): bool
    {
        if (class_exists('SparkInspector')) {
            SparkInspector::recordEvent($event, $data);
        }

        $result = static::runFile($event, $data);

        // Registered in-memory listeners
        foreach (static::$listeners[$event] ?? [] as $listener) {
            $listenerResult = $listener($data, $event);
            if ($listenerResult === false) {
                return false;
            }
        }

        return $result !== false;
    }

    private static function runFile(string $event, mixed $data): mixed
    {
        if (!static::$basePath) {
            return null;
        }

        $file = static::$basePath . '/app/events/' . $event . '.php';
        if (!file_exists($file)) {
            return null;
        }

        return (static function () use ($file, $data) {
            return require $file;
        })();
    }

    // ─────────────────────────────────────────────
    // In-memory listeners (for testing / dynamic use)
    // ─────────────────────────────────────────────

    public static function on(string $event, callable $listener): void
    {
        static::$listeners[$event][] = $listener;
    }

    public static function off(string $event, ?callable $listener = null): void
    {
        if ($listener === null) {
            unset(static::$listeners[$event]);
            return;
        }
        static::$listeners[$event] = array_filter(
            static::$listeners[$event] ?? [],
            fn($l) => $l !== $listener
        );
    }
}
