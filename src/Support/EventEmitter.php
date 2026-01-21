<?php

declare(strict_types=1);

namespace Fuel\Wss\Support;

final class EventEmitter
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, mixed ...$arguments): void
    {
        if (!array_key_exists($event, $this->listeners)) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener(...$arguments);
        }
    }
}
