<?php

namespace Flow\Middleware;

use FastRoute\Dispatcher;
use Invoker\Invoker;
use Invoker\InvokerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use RuntimeException;

/**
 * Route middleware. Terminal handler for matched routes.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 * @see https://mwop.net/blog/2018-01-23-psr-15.html
 */
final class RouterMiddleware implements MiddlewareInterface
{
    private readonly InvokerInterface $invoker;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
        $this->invoker = new Invoker();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeInfo = $this->dispatcher->dispatch(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return $this->responseFactory->createResponse(404);

            case Dispatcher::METHOD_NOT_ALLOWED:
                return $this->responseFactory
                    ->createResponse(405)
                    ->withHeader('allow', implode(', ', $routeInfo[1]));

            case Dispatcher::FOUND:
                /** @var array<string, string> $parameters */
                $parameters = $routeInfo[2] ?? [];
                /** @var mixed $handler */
                $handler = $routeInfo[1];

                if (!isset($parameters['request'])) {
                    $parameters['request'] = $request;
                }
                /** @var ResponseInterface */
                return $this->invoker->call($handler, $parameters);

            default:
                throw new RuntimeException('Unknown dispatch result');
        }
    }
}
