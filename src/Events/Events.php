<?php

namespace HZ\Illuminate\Mongez\Events;

use Illuminate\Support\Facades\App;

class Events implements EventsInterface
{
    /**
     * Events List
     *
     * @var array<string, array<int, string|callable>>
     */
    protected $eventsList = [];

    /**
     * Classes List
     *
     * @var array<string, object>
     */
    protected $classesList = [];

    /**
     * Baseline events list captured right after boot.
     *
     * It holds the listeners that were registered during the application
     * boot (config events + any boot-time `subscribe` calls). When running
     * under Laravel Octane, `reset` restores this baseline so boot-time
     * listeners survive while per-request listeners are discarded.
     *
     * @var array<string, array<int, string|callable>>
     */
    protected $baseEventsList = [];

    /**
     * An alias to trigger method
     * 
     * @see $this->trigger
     */
    public static function emit(mixed ...$args): mixed
    {
        return App::make(static::class)->trigger(...$args);
    }

    /**
     * Capture the current events list as the baseline.
     *
     * This should be called once after the application has booted to keep
     * the boot-time registered listeners when the state is reset between
     * requests on Laravel Octane.
     *
     * @return void
     */
    public function snapshotBaseState()
    {
        $this->baseEventsList = $this->eventsList;
    }

    /**
     * Reset the events and classes lists
     *
     * This is used between requests when running on Laravel Octane
     * to make sure no listeners or instances leak from one request to another.
     * The boot-time baseline listeners are restored, while any listeners added
     * during the current request are discarded.
     *
     * @return void
     */
    public function reset()
    {
        $this->eventsList = $this->baseEventsList;
        $this->classesList = [];
    }

    /** 
     * {@inheritDoc}
     */
    public function trigger(string $events, mixed ...$callbackArguments): mixed
    {
        $return = '';
        foreach (explode(' ', $events) as $event) {
            if (!isset($this->eventsList[$event])) continue;


            foreach ($this->eventsList[$event] as $callback) {
                if (is_string($callback)) {
                    if (!$this->isLoaded($callback)) {
                        $this->load($callback);
                    }

                    [$classObject, $method] = $this->get($callback);

                    $return = $classObject->$method(...$callbackArguments);
                } else {
                    $return = $callback(...$callbackArguments);
                }

                if ($return === false) return false;
                // change the first argument if the return data is altered
                if (!is_null($return)) {
                    $callbackArguments[0] = $return;
                }
            }
        }

        return $return;
    }

    /**
     * Check if the given class is loaded
     */
    protected function isLoaded(string $class): bool
    {
        [$class, $method] = explode('@', $class);
        return isset($this->classesList[$class]);
    }

    /**
     * Load the object of the given class
     *
     * @return void
     */
    protected function load(string $class)
    {
        [$class, $method] = explode('@', $class);

        $this->classesList[$class] = App::make($class);
    }

    /**
     * Get the class object and the method for the event
     * If the class doesn't have the method name i.e classPath@methodName
     * the `handle` method will be called instead
     *
     * @return array{0: object, 1: string} [$classObject, $methodName]
     */
    protected function get(string $class): array
    {
        [$class, $method] = explode('@', $class);

        return [$this->classesList[$class], $method ?: 'handle'];
    }

    /**
     * {@inherit}
     */
    /**
     * @param string|array<int, string> $eventListener
     */
    public function subscribe(string $events, string|array $eventListener): void
    {
        foreach (explode(' ', $events) as $event) {
            $this->eventsList[$event] ??= [];

            if (is_array($eventListener)) {
                $eventListener = implode('@', $eventListener);
            }

            if (!in_array($eventListener, $this->eventsList[$event])) {
                $this->eventsList[$event][] = $eventListener;
            }
        }
    }
}
