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
        // Implementation note: invokeRouteHandler only injects $request when
        // it is not already in the parameter map. A route param literally
        // named "request" therefore wins over the ServerRequest. Documents
        // current behaviour.
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

    public function testFoundCatchesHandlerExceptionAndReturns500(): void
    {
        $handler = function (): ResponseInterface {
            throw new RuntimeException('boom');
        };

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn([Dispatcher::FOUND, $handler, []]);

        $request = $this->factory->createServerRequest('GET', '/explodes');
        $middleware = new RouterMiddleware($dispatcher, $this->factory);

        // error_log writes to stderr — silence it for this test so the phpunit
        // output stays clean.
        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $middleware->process($request, Mockery::mock(RequestHandlerInterface::class));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(500, $response->getStatusCode());
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
