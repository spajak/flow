<?php

namespace Tests\Middleware;

use Flow\Middleware\CorsMiddleware;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddlewareTest extends MockeryTestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new Psr17Factory();
    }

    public function testNoOriginPassesThroughUnchanged(): void
    {
        $next = $this->handler($expected = $this->factory->createResponse(200));

        $middleware = new CorsMiddleware($this->factory, ['https://wcn.pl']);
        $response = $middleware->process($this->factory->createServerRequest('GET', '/'), $next);

        $this->assertSame($expected, $response);
        $this->assertFalse($response->hasHeader('access-control-allow-origin'));
        $this->assertFalse($response->hasHeader('vary'));
    }

    public function testPreflightAllowedOriginReturns204WithCorsHeaders(): void
    {
        $next = $this->handlerNeverCalled();

        $request = $this->factory->createServerRequest('OPTIONS', '/items')
            ->withHeader('Origin', 'https://wcn.pl')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $middleware = new CorsMiddleware(
            $this->factory,
            allowedOrigins: ['https://wcn.pl'],
            allowedMethods: ['GET', 'POST'],
            allowedHeaders: ['Content-Type'],
            allowCredentials: true,
            maxAge: 3600,
        );

        $response = $middleware->process($request, $next);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://wcn.pl', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertSame('GET, POST', $response->getHeaderLine('access-control-allow-methods'));
        $this->assertSame('Content-Type', $response->getHeaderLine('access-control-allow-headers'));
        $this->assertSame('3600', $response->getHeaderLine('access-control-max-age'));
        $this->assertSame('true', $response->getHeaderLine('access-control-allow-credentials'));
        $this->assertSame('Origin', $response->getHeaderLine('vary'));
    }

    public function testPreflightDisallowedOriginReturns204WithoutCorsHeaders(): void
    {
        $next = $this->handlerNeverCalled();

        $request = $this->factory->createServerRequest('OPTIONS', '/items')
            ->withHeader('Origin', 'https://evil.example')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $middleware = new CorsMiddleware($this->factory, ['https://wcn.pl']);
        $response = $middleware->process($request, $next);

        $this->assertSame(204, $response->getStatusCode());
        // No CORS headers granted → browser rejects the actual request.
        $this->assertFalse($response->hasHeader('access-control-allow-origin'));
        $this->assertFalse($response->hasHeader('access-control-allow-methods'));
        $this->assertFalse($response->hasHeader('access-control-allow-headers'));
    }

    public function testActualCrossOriginAllowedRequestDecorated(): void
    {
        $next = $this->handler($this->factory->createResponse(200)->withHeader('content-type', 'text/plain'));

        $request = $this->factory->createServerRequest('GET', '/items')
            ->withHeader('Origin', 'https://wcn.pl');

        $middleware = new CorsMiddleware($this->factory, ['https://wcn.pl'], allowCredentials: true);
        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain', $response->getHeaderLine('content-type'));
        $this->assertSame('https://wcn.pl', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertSame('true', $response->getHeaderLine('access-control-allow-credentials'));
        $this->assertSame('Origin', $response->getHeaderLine('vary'));
    }

    public function testActualCrossOriginDisallowedRequestNotDecorated(): void
    {
        $next = $this->handler($this->factory->createResponse(200));

        $request = $this->factory->createServerRequest('GET', '/items')
            ->withHeader('Origin', 'https://evil.example');

        $middleware = new CorsMiddleware($this->factory, ['https://wcn.pl']);
        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('access-control-allow-origin'));
        $this->assertFalse($response->hasHeader('vary'));
    }

    public function testWildcardOriginEmitsStarWhenNoCredentials(): void
    {
        $next = $this->handler($this->factory->createResponse(200));

        $request = $this->factory->createServerRequest('GET', '/items')
            ->withHeader('Origin', 'https://anywhere.example');

        $middleware = new CorsMiddleware($this->factory, ['*']);
        $response = $middleware->process($request, $next);

        $this->assertSame('*', $response->getHeaderLine('access-control-allow-origin'));
        $this->assertFalse($response->hasHeader('access-control-allow-credentials'));
    }

    public function testNonPreflightOptionsRequestIsForwarded(): void
    {
        // OPTIONS without Access-Control-Request-Method is NOT a preflight
        // (it's a regular OPTIONS request, e.g. for capability discovery).
        // It must be forwarded to the next handler, not short-circuited.
        $next = $this->handler($this->factory->createResponse(200)->withHeader('allow', 'GET, POST'));

        $request = $this->factory->createServerRequest('OPTIONS', '/items')
            ->withHeader('Origin', 'https://wcn.pl');

        $middleware = new CorsMiddleware($this->factory, ['https://wcn.pl']);
        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('allow'));
        $this->assertSame('https://wcn.pl', $response->getHeaderLine('access-control-allow-origin'));
    }

    public function testEmptyAllowlistThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one allowed origin');

        new CorsMiddleware($this->factory, []);
    }

    public function testWildcardWithCredentialsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('security misconfiguration');

        new CorsMiddleware($this->factory, ['*'], allowCredentials: true);
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldReceive('handle')
            ->once()
            ->with(Mockery::type(ServerRequestInterface::class))
            ->andReturn($response);
        /** @var RequestHandlerInterface */
        return $next;
    }

    private function handlerNeverCalled(): RequestHandlerInterface
    {
        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');
        /** @var RequestHandlerInterface */
        return $next;
    }
}
