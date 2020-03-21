<?php

namespace Flow\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Callback middleware.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
class CallbackMiddleware implements MiddlewareInterface
{
    protected $middleware;

    public function __construct(callable $middleware)
    {
        $this->middleware = $middleware;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return call_user_func($this->middleware, $request, $handler);
    }
}
