<?php

namespace Flow;

use Flow\Console\CommandLoader\LazyCommandLoader;
use Flow\Emitter\HttpEmitter;
use Flow\Middleware\RouterMiddleware;

use DI\Container;
use DI\ContainerBuilder;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\Dispatcher\GroupCountBased as RouteDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as StdRouteParser;
use Nyholm\Psr7\Factory\Psr17Factory as HttpFactory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application as Console;

use LogicException;

/**
 * Main application class.
 *
 * Lifecycle: register middleware on the broker, routes on the route collector,
 * services on the container builder, and commands on the console — all BEFORE
 * calling `run()`. The router middleware is appended last during bootstrap and
 * terminates the pipeline on a matched route, so anything appended after
 * `run()` is unreachable for matched routes.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
final class Application
{
    private bool $bootstrapped = false;
    private readonly Broker $broker;
    private readonly RouteCollector $routeCollector;
    private readonly Console $console;
    private readonly LazyCommandLoader $commandLoader;
    private readonly HttpFactory $httpFactory;
    /** @var ContainerBuilder<Container> */
    private readonly ContainerBuilder $containerBuilder;
    private ?Container $container = null;

    public function __construct()
    {
        // Broker (Handler & Middleware)
        $this->broker = new Broker();

        // Route Collector
        $this->routeCollector = new RouteCollector(
            new StdRouteParser(),
            new GroupCountBasedGenerator()
        );

        // Console
        $this->console = new Console();
        $this->commandLoader = new LazyCommandLoader();
        $this->console->setCommandLoader($this->commandLoader);

        // Psr7 Factories
        $this->httpFactory = new HttpFactory();

        // DI Container
        $this->containerBuilder = new ContainerBuilder();
        $this->containerBuilder->useAutowiring(false);
        $this->containerBuilder->useAttributes(false);

        $this->containerBuilder->addDefinitions([
            'console' => fn (): Console => $this->console,
            'http_factory' => fn (): HttpFactory => $this->httpFactory,
        ]);
    }

    /**
     * @return ContainerBuilder<Container>
     */
    public function getContainerBuilder(): ContainerBuilder
    {
        return $this->containerBuilder;
    }

    public function getBroker(): Broker
    {
        return $this->broker;
    }

    public function getRouteCollector(): RouteCollector
    {
        return $this->routeCollector;
    }

    public function getConsole(): Console
    {
        return $this->console;
    }

    public function getCommandLoader(): LazyCommandLoader
    {
        return $this->commandLoader;
    }

    public function getHttpFactory(): HttpFactory
    {
        return $this->httpFactory;
    }

    public function getContainer(): ContainerInterface
    {
        if (!$this->bootstrapped) {
            throw new LogicException('Container is not available before bootstrap.');
        }

        /** @var Container $container — narrowed by $bootstrapped */
        $container = $this->container;
        return $container;
    }

    public function getServerRequestCreator(): ServerRequestCreatorInterface
    {
        return new ServerRequestCreator(
            serverRequestFactory: $this->httpFactory,
            uriFactory: $this->httpFactory,
            uploadedFileFactory: $this->httpFactory,
            streamFactory: $this->httpFactory
        );
    }

    public function run(): void
    {
        $this->bootstrap();

        if (php_sapi_name() === 'cli') {
            $this->console->run();
        } else {
            $request = $this->getServerRequestCreator()->fromGlobals();
            $response = $this->broker->handle($request);
            (new HttpEmitter())->emit($response);
        }
    }

    private function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->container = $this->containerBuilder->build();

        $dispatcher = new RouteDispatcher($this->routeCollector->getData());
        $router = new RouterMiddleware($dispatcher, $this->httpFactory);
        $this->broker->append($router);

        $this->bootstrapped = true;
    }
}
