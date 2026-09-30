<?php

namespace webdna\auditor\tests\Support;

use Closure;
use Craft;
use ReflectionFunction;
use ReflectionProperty;
use yii\base\Component;
use yii\base\Event;

/**
 * Auditor switched off for part of a test: every event handler the plugin attached is taken away
 * while it runs, and put back afterwards.
 *
 * The plugin listens only through Yii events — class-level handlers from its watchers and its
 * plugin class, and instance handlers on the application (the end-of-request flush) and on a
 * database connection (the recorder following a transaction) — so with those gone a request runs
 * as it would with the plugin uninstalled. A handler is the plugin's when the object it is bound
 * to, or the class of a static one, is in the plugin's namespace; the tests' own are left alone.
 */
final class AuditorListeners
{
    /**
     * @template T
     * @param Closure(): T $run
     * @return T
     */
    public static function detached(Closure $run): mixed
    {
        $classLevel = new ReflectionProperty(Event::class, '_events');
        $instance = new ReflectionProperty(Component::class, '_events');
        /** @var array<string, array<string, array<int, array{0: mixed, 1: mixed}>>> $classEvents */
        $classEvents = $classLevel->getValue();
        $components = [Craft::$app, Craft::$app->getDb()];
        $componentEvents = array_map(fn(Component $component) => $instance->getValue($component), $components);

        $classLevel->setValue(null, array_map(
            fn(array $classes) => array_map(fn(array $handlers) => self::without($handlers), $classes),
            $classEvents,
        ));
        foreach ($components as $i => $component) {
            $instance->setValue($component, array_map(fn(array $handlers) => self::without($handlers), $componentEvents[$i]));
        }

        try {
            return $run();
        } finally {
            $classLevel->setValue(null, $classEvents);
            foreach ($components as $i => $component) {
                $instance->setValue($component, $componentEvents[$i]);
            }
        }
    }

    /**
     * How many of the plugin's handlers are attached now — so a test can show there was something
     * to take away.
     */
    public static function count(): int
    {
        $count = 0;
        /** @var array<string, array<string, array<int, array{0: mixed, 1: mixed}>>> $classEvents */
        $classEvents = (new ReflectionProperty(Event::class, '_events'))->getValue();
        foreach ($classEvents as $classes) {
            foreach ($classes as $handlers) {
                $count += count($handlers) - count(self::without($handlers));
            }
        }

        return $count;
    }

    /**
     * @param array<int, array{0: mixed, 1: mixed}> $handlers
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    private static function without(array $handlers): array
    {
        return array_values(array_filter($handlers, fn(array $handler) => !self::ours($handler[0])));
    }

    private static function ours(mixed $handler): bool
    {
        $owner = match (true) {
            is_array($handler) => $handler[0] ?? null,
            $handler instanceof Closure => (new ReflectionFunction($handler))->getClosureThis()
                ?? (new ReflectionFunction($handler))->getClosureScopeClass()?->getName(),
            default => null,
        };
        $class = is_object($owner) ? $owner::class : (string)$owner;

        return str_starts_with($class, 'webdna\\auditor\\') && !str_starts_with($class, 'webdna\\auditor\\tests\\');
    }
}
