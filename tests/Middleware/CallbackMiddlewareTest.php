<?php

namespace Tests\Middleware;

use Flow\Middleware\CallbackMiddleware;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CallbackMiddlewareTest extends MockeryTestCase
{
    public function testCallbackReceivesRequestAndHandler(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $handler = Mockery::mock(RequestHandlerInterface::class);

        $captured = [];
        $middleware = new CallbackMiddleware(
            function ($req, $h) use ($factory, &$captured): ResponseInterface {
                $captured = ['request' => $req, 'handler' => $h];
                return $factory->createResponse(200);
            }
        );

        $middleware->process($request, $handler);

        $this->assertSame($request, $captured['request']);
        $this->assertSame($handler, $captured['handler']);
    }

    public function testCallbackReturnsResponseFromCallable(): void
    {
        $factory = new Psr17Factory();
        $expected = $factory->createResponse(201);

        $middleware = new CallbackMiddleware(
            fn (ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface => $expected
        );

        $response = $middleware->process(
            $factory->createServerRequest('GET', '/'),
            Mockery::mock(RequestHandlerInterface::class)
        );

        $this->assertSame($expected, $response);
    }

    public function testCallbackCanDelegateToTheNextHandler(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')
            ->withAttribute('seen-by-callback', false);
        $expected = $factory->createResponse(200);

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (ServerRequestInterface $req) use ($expected): ResponseInterface {
                // Inner handler should observe the attribute mutation.
                /** @var bool $seen */
                $seen = $req->getAttribute('seen-by-callback');
                if ($seen !== true) {
                    throw new \LogicException('callback did not mutate request');
                }
                return $expected;
            });

        $middleware = new CallbackMiddleware(
            fn ($req, $h): ResponseInterface => $h->handle($req->withAttribute('seen-by-callback', true))
        );

        $response = $middleware->process($request, $next);

        $this->assertSame($expected, $response);
    }
}
