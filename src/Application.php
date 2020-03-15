<?php

namespace Flow;

use DI\ContainerBuilder;
use DI\Container;
use Psr\Container\ContainerInterface;
use Northwoods\Broker\Broker;
use FastRoute\RouteCollector;
use FastRoute\Dispatcher\GroupCountBased as RouteDispatcher;
use FastRoute\RouteParser\Std as StdRouteParser;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use Symfony\Component\Console\Application as Console;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Psr\Http\Message\ServerRequestInterface;
use Flow\Middleware\RouterMiddleware;
use Flow\Emitter\HttpEmitter;
use Nyholm\Psr7\Factory\Psr17Factory as HttpFactory;
use Nyholm\Psr7Server\ServerRequestCreator;

/**
 * Main application class
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
final class Application
{
    private $bootstrapped;
    private $broker;
    private $routeCollector;
    private $console;
    private $httpFactory;
    private $containerBuilder;
    private $container;

    public function __construct()
    {
        // Broker
        $this->broker = new Broker;

        // Route Collector
        $this->routeCollector = new RouteCollector(
            new StdRouteParser,
            new GroupCountBasedGenerator
        );

        // Console
        $this->console = new Console;

        // Psr7 Factory
        $this->httpFactory = new HttpFactory;

        // DI Container
        $this->containerBuilder = new ContainerBuilder;
        $this->containerBuilder->useAutowiring(false);
        $this->containerBuilder->useAnnotations(false);

        $this->containerBuilder->addDefinitions([
            'console' => function() { return $this->console; },
            'http_factory' => function() { return $this->httpFactory; }
        ]);
    }

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

    public function setConsoleCommandsLoader(array $commands)
    {
        $loader = new FactoryCommandLoader($commands);
        $this->console->setCommandLoader($loader);
    }

    public function getHttpFactory(): HttpFactory
    {
        return $this->httpFactory;
    }

    public function getContainer(): ContainerInterface
    {
        if (!isset($this->container)) {
            return new Container;
        }
        return $this->container;
    }

    public function run(): void
    {
        $this->bootstrap();

        if (php_sapi_name() === 'cli') {
            $this->console->run();
        } else {
            $request = $this->createServerRequest();
            $response = $this->broker->handle($request);
            $emitter = new HttpEmitter;
            $emitter->emit($response);
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

    private function createServerRequest(): ServerRequestInterface
    {
        $creator = new ServerRequestCreator(
            $this->httpFactory, // ServerRequestFactory
            $this->httpFactory, // UriFactory
            $this->httpFactory, // UploadedFileFactory
            $this->httpFactory  // StreamFactory
        );

        $request = $creator->fromGlobals();
        return $request;
    }
}
