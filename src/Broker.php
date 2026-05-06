<?php

namespace Flow;

use Flow\Middleware\LazyMiddleware;
use Northwoods\Broker\Broker as BaseBroker;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Broker with deferred middleware support.
 *
 * Middleware registered via `appendDeferred()` / `prependDeferred()` is built
 * lazily on first dispatch — useful when the constructor needs services from
 * the container, which only becomes available after `Application::bootstrap()`.
 */
class Broker extends BaseBroker
{
    /**
     * Append middleware that will be constructed on first request.
     *
     * @param callable(): MiddlewareInterface ...$factories
     */
    public function appendDeferred(callable ...$factories): static
    {
        foreach ($factories as $factory) {
            $this->append(new LazyMiddleware($factory));
        }
        return $this;
    }

    /**
     * Prepend middleware that will be constructed on first request.
     *
     * @param callable(): MiddlewareInterface ...$factories
     */
    public function prependDeferred(callable ...$factories): static
    {
        foreach ($factories as $factory) {
            $this->prepend(new LazyMiddleware($factory));
        }
        return $this;
    }
}
