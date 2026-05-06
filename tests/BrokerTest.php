<?php

namespace Tests;

use Flow\Broker;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Northwoods\Broker\Broker as BaseBroker;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BrokerTest extends MockeryTestCase
{
    public function testIsInstanceOfNorthwoodsBroker(): void
    {
        $this->assertInstanceOf(BaseBroker::class, new Broker());
    }

    public function testAppendDeferredDoesNotInvokeFactoryUntilDispatch(): void
    {
        $broker = new Broker();
        $invocations = 0;

        $broker->appendDeferred(function () use (&$invocations) {
            $invocations++;
            return $this->passThroughMiddleware();
        });

        $this->assertSame(0, $invocations, 'factory must stay dormant after registration');
    }

    public function testAppendDeferredRunsFactoryOnceOnFirstHandle(): void
    {
        $broker = new Broker();
        $invocations = 0;

        $broker->appendDeferred(function () use (&$invocations): MiddlewareInterface {
            $invocations++;
            return $this->passThroughMiddleware();
        });

        $broker->append($this->terminatingMiddleware(200));

        $this->dispatch($broker);
        $this->dispatch($broker);

        $this->assertSame(1, $invocations);
    }

    public function testAppendDeferredAcceptsMultipleFactoriesInOrder(): void
    {
        $broker = new Broker();
        $order = [];

        // Build mocks outside the deferred closures so the &$sink reference
        // binds to the outer $order — arrow-fn auto-capture is by-value.
        $first  = $this->orderRecordingMiddleware('first', $order);
        $second = $this->orderRecordingMiddleware('second', $order);

        $broker->appendDeferred(
            fn (): MiddlewareInterface => $first,
            fn (): MiddlewareInterface => $second,
        );

        $broker->append($this->terminatingMiddleware(200));

        $this->dispatch($broker);

        $this->assertSame(['first', 'second'], $order);
    }

    public function testPrependDeferredRunsBeforeAlreadyAppendedMiddleware(): void
    {
        $broker = new Broker();
        $order = [];

        $appended  = $this->orderRecordingMiddleware('appended', $order);
        $prepended = $this->orderRecordingMiddleware('prepended', $order);

        $broker->append($appended);
        $broker->prependDeferred(fn (): MiddlewareInterface => $prepended);

        $broker->append($this->terminatingMiddleware(200));

        $this->dispatch($broker);

        $this->assertSame(['prepended', 'appended'], $order);
    }

    public function testReturnsStaticForChaining(): void
    {
        $broker = new Broker();

        $this->assertSame($broker, $broker->appendDeferred(fn () => $this->passThroughMiddleware()));
        $this->assertSame($broker, $broker->prependDeferred(fn () => $this->passThroughMiddleware()));
    }

    private function dispatch(Broker $broker): ResponseInterface
    {
        return $broker->handle((new Psr17Factory())->createServerRequest('GET', '/'));
    }

    private function passThroughMiddleware(): MiddlewareInterface
    {
        $mw = Mockery::mock(MiddlewareInterface::class);
        $mw->shouldReceive('process')
            ->andReturnUsing(fn (ServerRequestInterface $req, RequestHandlerInterface $h) => $h->handle($req));
        return $mw;
    }

    private function terminatingMiddleware(int $status): MiddlewareInterface
    {
        $factory = new Psr17Factory();
        $mw = Mockery::mock(MiddlewareInterface::class);
        $mw->shouldReceive('process')->andReturn($factory->createResponse($status));
        return $mw;
    }

    /** @param list<string> $sink */
    private function orderRecordingMiddleware(string $tag, array &$sink): MiddlewareInterface
    {
        $mw = Mockery::mock(MiddlewareInterface::class);
        $mw->shouldReceive('process')
            ->andReturnUsing(function (ServerRequestInterface $req, RequestHandlerInterface $h) use ($tag, &$sink) {
                $sink[] = $tag;
                return $h->handle($req);
            });
        return $mw;
    }
}
