<?php

namespace Flow\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Closure;

final class ConditionalMiddleware implements MiddlewareInterface
{
    private readonly Closure $condition;

    public function __construct(
        private readonly MiddlewareInterface $middleware,
        callable $condition,
    ) {
        $this->condition = $condition(...);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (($this->condition)($request) === true) {
            return $this->middleware->process($request, $handler);
        }

        return $handler->handle($request);
    }
}
