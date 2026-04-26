<?php

namespace Flow\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Closure;

final class CallbackMiddleware implements MiddlewareInterface
{
    private readonly Closure $middleware;

    public function __construct(callable $middleware)
    {
        $this->middleware = $middleware(...);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($this->middleware)($request, $handler);
    }
}
