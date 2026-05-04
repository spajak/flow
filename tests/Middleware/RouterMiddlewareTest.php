<?php

namespace Tests\Middleware;

use FastRoute\Dispatcher;
use Flow\Middleware\RouterMiddleware;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class RouterMiddlewareTest extends MockeryTestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new Psr17Factory();
    }

    public function testNotFoundReturns404AndDoesNotCallNext(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('GET', '/missing')
            ->andReturn([Dispatcher::NOT_FOUND]);

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $request = $this->factory->createServerRequest('GET', '/missing');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        $response = $middleware->process($request, $next);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('POST', '/items')
            ->andReturn([Dispatcher::METHOD_NOT_ALLOWED, ['GET', 'PUT']]);

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $request = $this->factory->createServerRequest('POST', '/items');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        $response = $middleware->process($request, $next);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, PUT', $response->getHeaderLine('allow'));
    }

    public function testOptionsWithOriginReflectsItAndAllowsCredentials(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->with('OPTIONS', '/items')
            ->andReturn([Dispatcher::METHOD_NOT_ALLOWED, ['GET', 'POST']]);

        $request = $this->factory->createServerRequest('OPTIONS', '/items')
            ->withHeader('Origin', 'https://wcn.pl');
        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $response = $middleware->process($request, $next);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('allow'));
        $this->assertSame('GET, POST', $response->getHeaderLine('access-control-allow-methods'));
        $this->assertSame('*', $response->getHeaderLine('access-control-allow-headers'));
        $this->assertSame('https://wcn.pl', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertSame('true', $response->getHeaderLine('access-control-allow-credentials'));
        $this->assertSame('Origin', $response->getHeaderLine('vary'));
    }

    public function testOptionsWithoutOriginFallsBackToWildcardWithoutCredentials(): void
    {
        // No Origin header → request isn't cross-origin per the spec, so we
        // emit a wildcard ACAO without credentials. Spec-compliant pairing.
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->with('OPTIONS', '/items')
            ->andReturn([Dispatcher::METHOD_NOT_ALLOWED, ['GET', 'POST']]);

        $request = $this->factory->createServerRequest('OPTIONS', '/items');
        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $response = $middleware->process($request, $next);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('allow'));
        $this->assertSame('GET, POST', $response->getHeaderLine('access-control-allow-methods'));
        $this->assertSame('*', $response->getHeaderLine('access-control-allow-headers'));
        $this->assertSame('*', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertSame('', $response->getHeaderLine('access-control-allow-credentials'));
        $this->assertSame('', $response->getHeaderLine('vary'));
    }

    public function testFoundInvokesHandlerAndReturnsItsResponse(): void
    {
        $expected = $this->factory->createResponse(201)->withHeader('x-test', 'yes');
        $handler = function (ServerRequestInterface $request) use ($expected): ResponseInterface {
            return $expected;
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, []]);

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $request = $this->factory->createServerRequest('GET', '/hello');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        $response = $middleware->process($request, $next);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('x-test'));
    }

    public function testFoundDoesNotCallTheNextMiddleware(): void
    {
        // Locks current behaviour: RouterMiddleware terminates the pipeline on
        // a matched route. Any middleware appended AFTER it is dead code.
        // Tracked in the framework review.
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturn([
            Dispatcher::FOUND,
            fn () => $this->factory->createResponse(200),
            [],
        ]);

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $request = $this->factory->createServerRequest('GET', '/');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $middleware->process($request, $next);
    }

    public function testFoundPassesRouteParametersToHandlerByName(): void
    {
        $captured = [];
        $handler = function (string $id, ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = ['id' => $id, 'method' => $request->getMethod()];
            return (new Psr17Factory())->createResponse(200);
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, ['id' => '42']]);

        $request = $this->factory->createServerRequest('GET', '/items/42');
        $next = Mockery::mock(RequestHandlerInterface::class);

        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('42', $captured['id']);
        $this->assertSame('GET', $captured['method']);
    }

    public function testFoundInjectsServerRequestUnderRequestParameter(): void
    {
        $captured = null;
        $handler = function (ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = $request;
            return (new Psr17Factory())->createResponse(204);
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, []]);

        $request = $this->factory->createServerRequest('GET', '/health')
            ->withAttribute('user', 'admin');

        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));

        $this->assertInstanceOf(ServerRequestInterface::class, $captured);
        $this->assertSame('admin', $captured->getAttribute('user'));
    }

    public function testRouteParameterNamedRequestIsNotOverridden(): void
    {
        // The FOUND branch only injects the ServerRequest under the "request"
        // key when it is not already present in the parameter map. So a route
        // param literally named "request" wins over the ServerRequest.
        // Documents current behaviour.
        $captured = null;
        $handler = function ($request) use (&$captured): ResponseInterface {
            $captured = $request;
            return (new Psr17Factory())->createResponse(200);
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, ['request' => 'route-supplied-string']]);

        $serverRequest = $this->factory->createServerRequest('GET', '/');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $middleware->process($serverRequest, Mockery::mock(RequestHandlerInterface::class));

        $this->assertSame('route-supplied-string', $captured);
    }

    public function testDispatcherIsCalledWithMethodAndPathOnly(): void
    {
        // Querystrings must NOT be passed to fast-route (it dispatches on path).
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with('PATCH', '/items/7')
            ->andReturn([Dispatcher::NOT_FOUND]);

        $request = $this->factory->createServerRequest('PATCH', '/items/7?foo=bar&baz=1');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));
    }

    public function testUnknownDispatchResultThrows(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([99]); // not NOT_FOUND, METHOD_NOT_ALLOWED, or FOUND

        $request = $this->factory->createServerRequest('GET', '/');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        $this->expectException(RuntimeException::class);
        $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));
    }

    public function testFoundLetsHandlerExceptionsPropagate(): void
    {
        // Routing and error handling are separate concerns. The router
        // dispatches; if the handler throws, the exception propagates up to
        // the application's error-handling middleware (or PHP's global
        // exception handler if none is installed). The router does not
        // fabricate a 500 response or log on its own.
        $handler = function (): ResponseInterface {
            throw new RuntimeException('boom');
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, []]);

        $request = $this->factory->createServerRequest('GET', '/explodes');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));
    }

    public function testFoundWithMultipleRouteParameters(): void
    {
        // Locks multi-parameter injection. Existing FOUND tests only proved
        // the single-parameter case; a regression in php-di's Invoker
        // signature-matching for two-or-more route params would slip through.
        $captured = [];
        $handler = function (string $a, string $b, ServerRequestInterface $request) use (&$captured): ResponseInterface {
            $captured = ['a' => $a, 'b' => $b, 'method' => $request->getMethod()];
            return (new Psr17Factory())->createResponse(200);
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, ['a' => '1', 'b' => '2']]);

        $request = $this->factory->createServerRequest('GET', '/items/1/2');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $response = $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1', $captured['a']);
        $this->assertSame('2', $captured['b']);
        $this->assertSame('GET', $captured['method']);
    }

    public function testOptionsOnFoundRouteBypassesCorsLogic(): void
    {
        // When OPTIONS is registered as an explicit route, the FOUND branch
        // runs the handler and returns its response unchanged. The CORS
        // preflight machinery in methodNotAllowed() is NOT invoked, so
        // Access-Control-Allow-* headers are absent unless the handler sets
        // them itself. Documents and locks this behaviour against accidental
        // "fixes" that would conflate the two paths.
        $handler = fn (): ResponseInterface => $this->factory
            ->createResponse(200)
            ->withHeader('x-handled', 'yes');

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->with('OPTIONS', '/items')
            ->andReturn([Dispatcher::FOUND, $handler, []]);

        $request = $this->factory->createServerRequest('OPTIONS', '/items')
            ->withHeader('Origin', 'https://wcn.pl');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $response = $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('x-handled'));
        // No CORS headers from methodNotAllowed leaked through.
        $this->assertFalse($response->hasHeader('access-control-allow-methods'));
        $this->assertFalse($response->hasHeader('access-control-allow-headers'));
        $this->assertFalse($response->hasHeader('access-control-allow-origin'));
        $this->assertFalse($response->hasHeader('access-control-allow-credentials'));
        $this->assertFalse($response->hasHeader('vary'));
        $this->assertFalse($response->hasHeader('allow'));
    }

    public function testHandlerCanBeAnInvokableObject(): void
    {
        $handler = new class {
            public ?ServerRequestInterface $captured = null;
            public function __invoke(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return (new Psr17Factory())->createResponse(202);
            }
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, []]);

        $request = $this->factory->createServerRequest('POST', '/queue');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);
        $response = $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertNotNull($handler->captured);
        $this->assertSame('POST', $handler->captured->getMethod());
    }
}
