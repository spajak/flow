<?php

namespace Tests\Middleware;

use Flow\Middleware\ConditionalMiddleware;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ConditionalMiddlewareTest extends MockeryTestCase
{
    public function testInnerProcessesWhenConditionIsTrue(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $expected = $factory->createResponse(200);

        $inner = Mockery::mock(MiddlewareInterface::class);
        $inner->shouldReceive('process')
            ->once()
            ->andReturn($expected);

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldNotReceive('handle');

        $middleware = new ConditionalMiddleware($inner, fn (): bool => true);
        $response = $middleware->process($request, $next);

        $this->assertSame($expected, $response);
    }

    public function testHandlerIsCalledDirectlyWhenConditionIsFalse(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $expected = $factory->createResponse(204);

        $inner = Mockery::mock(MiddlewareInterface::class);
        $inner->shouldNotReceive('process');

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldReceive('handle')
            ->once()
            ->with($request)
            ->andReturn($expected);

        $middleware = new ConditionalMiddleware($inner, fn (): bool => false);
        $response = $middleware->process($request, $next);

        $this->assertSame($expected, $response);
    }

    public function testConditionReceivesTheRequest(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/test')
            ->withAttribute('user', 'anna');

        $captured = null;
        $inner = Mockery::mock(MiddlewareInterface::class);
        $inner->shouldNotReceive('process');

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldReceive('handle')
            ->andReturn($factory->createResponse(200));

        $middleware = new ConditionalMiddleware(
            $inner,
            function (ServerRequestInterface $r) use (&$captured): bool {
                $captured = $r;
                return false;
            }
        );

        $middleware->process($request, $next);

        $this->assertSame($request, $captured);
    }

    public function testNonStrictTrueDoesNotTriggerInner(): void
    {
        // The check is `=== true`, so a truthy value like 1 should NOT
        // run the inner middleware. Documents the strict contract.
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');

        $inner = Mockery::mock(MiddlewareInterface::class);
        $inner->shouldNotReceive('process');

        $next = Mockery::mock(RequestHandlerInterface::class);
        $next->shouldReceive('handle')
            ->once()
            ->andReturn($factory->createResponse(200));

        $middleware = new ConditionalMiddleware(
            $inner,
            /** @return mixed */
            fn () => 1
        );

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }
}
