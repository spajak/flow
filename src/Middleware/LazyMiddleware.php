<?php

namespace Flow\Middleware;

use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 wrapper that defers middleware construction until first dispatch.
 *
 * Lets middleware that depends on container services be registered during
 * bootstrap, before the container itself is built.
 */
final class LazyMiddleware implements MiddlewareInterface
{
    private ?MiddlewareInterface $real = null;

    /** @param callable(): MiddlewareInterface $factory */
    public function __construct(private $factory) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->real === null) {
            $real = ($this->factory)();
            if (!$real instanceof MiddlewareInterface) {
                throw new LogicException(sprintf(
                    'Lazy middleware factory must return a %s; got %s.',
                    MiddlewareInterface::class,
                    get_debug_type($real),
                ));
            }
            $this->real = $real;
        }
        return $this->real->process($request, $handler);
    }
}
