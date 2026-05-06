<?php

namespace Flow\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 wrapper that defers middleware construction until first dispatch.
 *
 * Lets middleware that depends on container services be registered during
 * bootstrap, before the container itself is built. A factory that returns
 * the wrong type is caught by PHP's property type enforcement on assignment.
 */
final class LazyMiddleware implements MiddlewareInterface
{
    private ?MiddlewareInterface $real = null;

    /** @param callable(): MiddlewareInterface $factory */
    public function __construct(private $factory) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->real ??= ($this->factory)();
        return $this->real->process($request, $handler);
    }
}
