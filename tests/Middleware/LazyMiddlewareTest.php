<?php

namespace Tests\Middleware;

use Flow\Middleware\LazyMiddleware;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use TypeError;

final class LazyMiddlewareTest extends MockeryTestCase
{
    public function testFactoryIsNotInvokedAtConstruction(): void
    {
        $invocations = 0;
        new LazyMiddleware(function () use (&$invocations) {
            $invocations++;
            return Mockery::mock(MiddlewareInterface::class);
        });

        $this->assertSame(0, $invocations);
    }

    public function testFactoryIsInvokedOnFirstProcessOnly(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $expected = $factory->createResponse(200);

        $invocations = 0;
        $real = Mockery::mock(MiddlewareInterface::class);
        $real->shouldReceive('process')->twice()->andReturn($expected);

        $lazy = new LazyMiddleware(function () use (&$invocations, $real) {
            $invocations++;
            return $real;
        });

        $lazy->process($request, $handler);
        $lazy->process($request, $handler);

        $this->assertSame(1, $invocations, 'factory must be invoked exactly once across calls');
    }

    public function testProcessForwardsRequestAndHandlerToInnerMiddleware(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/foo');
        $handler = Mockery::mock(RequestHandlerInterface::class);
        $expected = $factory->createResponse(204);

        $real = Mockery::mock(MiddlewareInterface::class);
        $real->shouldReceive('process')
            ->once()
            ->withArgs(fn ($req, $h) => $req === $request && $h === $handler)
            ->andReturn($expected);

        $lazy = new LazyMiddleware(fn (): MiddlewareInterface => $real);

        $response = $lazy->process($request, $handler);

        $this->assertSame($expected, $response);
    }

    public function testThrowsWhenFactoryReturnsNonMiddleware(): void
    {
        // Property type enforcement on $real catches a bad factory return at
        // assignment — no custom validation in LazyMiddleware itself.
        $factory = new Psr17Factory();
        $lazy = new LazyMiddleware(fn () => new stdClass());

        $this->expectException(TypeError::class);

        $lazy->process(
            $factory->createServerRequest('GET', '/'),
            Mockery::mock(RequestHandlerInterface::class),
        );
    }
}
