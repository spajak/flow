<?php

namespace Flow\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use FastRoute\Dispatcher;
use Invoker\Invoker;
use RuntimeException;

/**
 * Route middleware.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 * @see https://mwop.net/blog/2018-01-23-psr-15.html
 */
class RouterMiddleware implements MiddlewareInterface
{
    protected Dispatcher $dispatcher;
    protected ResponseFactoryInterface $responseFactory;

    public function __construct(Dispatcher $dispatcher, ResponseFactoryInterface $responseFactory)
    {
        $this->dispatcher = $dispatcher;
        $this->responseFactory = $responseFactory;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeInfo = $this->dispatcher->dispatch(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $response = $this->responseFactory->createResponse(404);
                break;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = implode(', ', $routeInfo[1]);
                if ($request->getMethod() === 'OPTIONS') {
                    $response = $this->responseFactory
                        ->createResponse(200)
                        ->withHeader('access-control-allow-origin', '*')
                        ->withHeader('access-control-allow-methods', $allowedMethods)
                        ->withHeader('access-control-allow-headers', '*')
                        ->withHeader('access-control-allow-credentials', 'true');
                } else {
                    $response = $this->responseFactory
                        ->createResponse(405)
                        ->withHeader('allow', $allowedMethods);
                }
                break;
            case Dispatcher::FOUND:
                $response = $this->invokeRouteHandler($request, $routeInfo);
                break;
            default:
                throw new RuntimeException('Unknown dispatch result');
        }

        return $response;
    }

    private function invokeRouteHandler(RequestInterface $request, array $routeInfo): ResponseInterface
    {
        $handler = $routeInfo[1];
        $parameters = $routeInfo[2] ?? [];
        if (!isset($parameters['request'])) {
            $parameters['request'] = $request;
        }

        return (new Invoker())->call($handler, $parameters);
    }
}
