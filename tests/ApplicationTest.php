<?php

namespace Tests;

use DI\Container;
use DI\ContainerBuilder;
use FastRoute\RouteCollector;
use Flow\Application;
use Flow\Console\CommandLoader\LazyCommandLoader;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Northwoods\Broker\Broker;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Symfony\Component\Console\Application as Console;

final class ApplicationTest extends MockeryTestCase
{
    public function testGettersReturnConcreteCollaborators(): void
    {
        $app = new Application();

        $this->assertInstanceOf(Broker::class, $app->getBroker());
        $this->assertInstanceOf(RouteCollector::class, $app->getRouteCollector());
        $this->assertInstanceOf(Console::class, $app->getConsole());
        $this->assertInstanceOf(LazyCommandLoader::class, $app->getCommandLoader());
        $this->assertInstanceOf(Psr17Factory::class, $app->getHttpFactory());
        $this->assertInstanceOf(ContainerBuilder::class, $app->getContainerBuilder());
    }

    public function testGettersReturnSameInstanceAcrossCalls(): void
    {
        $app = new Application();

        $this->assertSame($app->getBroker(), $app->getBroker());
        $this->assertSame($app->getRouteCollector(), $app->getRouteCollector());
        $this->assertSame($app->getConsole(), $app->getConsole());
        $this->assertSame($app->getHttpFactory(), $app->getHttpFactory());
    }

    public function testGetServerRequestCreatorReturnsNyholmCreator(): void
    {
        $app = new Application();
        $creator = $app->getServerRequestCreator();

        $this->assertInstanceOf(ServerRequestCreatorInterface::class, $creator);
        $this->assertInstanceOf(ServerRequestCreator::class, $creator);
    }

    public function testGetContainerBeforeBootstrapThrows(): void
    {
        $app = new Application();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/bootstrap/i');

        $app->getContainer();
    }

    public function testContainerBuilderRegistersConsoleAndHttpFactory(): void
    {
        $app = new Application();
        $container = $app->getContainerBuilder()->build();

        $this->assertTrue($container->has('console'));
        $this->assertTrue($container->has('http_factory'));
        $this->assertSame($app->getConsole(), $container->get('console'));
        $this->assertSame($app->getHttpFactory(), $container->get('http_factory'));
    }

    public function testBootstrapBuildsContainerAndAppendsRouter(): void
    {
        $app = new Application();
        $this->bootstrap($app);

        // After bootstrap, getContainer() returns the *built* container with
        // the predefined `console` and `http_factory` services.
        $container = $app->getContainer();
        $this->assertSame($app->getConsole(), $container->get('console'));
        $this->assertSame($app->getHttpFactory(), $container->get('http_factory'));

        // RouterMiddleware was appended → broker now answers 404 for any path.
        $request = $app->getHttpFactory()->createServerRequest('GET', '/no/such/route');
        $response = $app->getBroker()->handle($request);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testBootstrapIsIdempotent(): void
    {
        $app = new Application();

        $this->bootstrap($app);
        $firstContainer = $app->getContainer();

        $this->bootstrap($app);
        $secondContainer = $app->getContainer();

        $this->assertSame(
            $firstContainer,
            $secondContainer,
            'Calling bootstrap twice must not rebuild the container.'
        );
    }

    public function testBootstrappedFlagIsSet(): void
    {
        $app = new Application();
        $reflection = new ReflectionClass($app);
        $flag = $reflection->getProperty('bootstrapped');

        $this->assertFalse(
            $flag->getValue($app),
            'bootstrapped defaults to false.'
        );

        $this->bootstrap($app);

        $this->assertTrue($flag->getValue($app));
    }

    public function testRouteAddedBeforeBootstrapIsDispatchable(): void
    {
        $app = new Application();
        $expected = $app->getHttpFactory()->createResponse(201);

        $app->getRouteCollector()->addRoute('GET', '/ping', function () use ($expected) {
            return $expected;
        });

        $this->bootstrap($app);

        $request = $app->getHttpFactory()->createServerRequest('GET', '/ping');
        $response = $app->getBroker()->handle($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testUserMiddlewareAppendedBeforeBootstrapRunsBeforeRouter(): void
    {
        $app = new Application();
        $factory = $app->getHttpFactory();

        $app->getBroker()->append(new class ($factory) implements \Psr\Http\Server\MiddlewareInterface {
            public function __construct(private \Psr\Http\Message\ResponseFactoryInterface $factory) {}
            public function process(
                \Psr\Http\Message\ServerRequestInterface $request,
                \Psr\Http\Server\RequestHandlerInterface $handler,
            ): \Psr\Http\Message\ResponseInterface {
                return $this->factory->createResponse(418); // I'm a teapot
            }
        });

        $this->bootstrap($app);

        $request = $factory->createServerRequest('GET', '/whatever');
        $response = $app->getBroker()->handle($request);

        $this->assertSame(418, $response->getStatusCode());
    }

    private function bootstrap(Application $app): void
    {
        (new ReflectionClass($app))->getMethod('bootstrap')->invoke($app);
    }
}
