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
                return $this->methodNotAllowed($request, $routeInfo[1]);

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

    /**
     * @param list<string> $allowed Allowed HTTP methods for this route.
     */
    private function methodNotAllowed(ServerRequestInterface $request, array $allowed): ResponseInterface
    {
        $allowedMethods = implode(', ', $allowed);

        if ($request->getMethod() !== 'OPTIONS') {
            return $this->responseFactory
                ->createResponse(405)
                ->withHeader('allow', $allowedMethods);
        }

        // Permissive CORS preflight fallback. Reflects Origin when present so
        // credentialed cross-origin requests work; falls back to wildcard
        // (no credentials) otherwise. Users who need stricter rules should
        // add their own middleware.
        $response = $this->responseFactory
            ->createResponse(204)
            ->withHeader('allow', $allowedMethods)
            ->withHeader('access-control-allow-methods', $allowedMethods)
            ->withHeader('access-control-allow-headers', '*');

        $origin = $request->getHeaderLine('origin');
        if ($origin !== '') {
            return $response
                ->withHeader('access-control-allow-origin', $origin)
                ->withHeader('access-control-allow-credentials', 'true')
                ->withHeader('vary', 'Origin');
        }

        return $response->withHeader('access-control-allow-origin', '*');
    }
}
