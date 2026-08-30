<?php

declare(strict_types=1);

namespace HZ\Illuminate\Mongez\Events;

interface EventsInterface
{
    /**
     * Trigger the given event(s) and pass the given arguments to any callback that
     * is listening to that event
     * Multiple events could be triggered with one method by adding space between each event
     * 
     * @return  string $events
     * @return  mixed ...$callbackArguments
     * @return mixed
     */
    public function trigger(string $events, mixed ...$callbackArguments): mixed;

    /**
     * Subscribe to the given event name, or in other words add event listener
     *
     * @return  string $events
     * @return  string|array|callable $callback
     * @return void
     */
    /**
     * @param string|array<int, string>|callable $callback
     */
    public function subscribe(string $events, string|array|callable $callback): void;
}
