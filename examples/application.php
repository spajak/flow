<?php

require __DIR__.'/../vendor/autoload.php';

$app = new Flow\Application;

// Register services (using: php-di/php-di)
// -----------------------------------------------------------------------------
$app->getContainerBuilder()->addDefinitions([
    'hello' => function() {
        return new class {
            public function sayHello($name) { return "Hello {$name}!"; }
        };
    }
]);

// Register routes (using: nikic/fast-route)
// -----------------------------------------------------------------------------
$app->getRouteCollector()->get('/hello[/{name}]', function($request, $name = 'World') use ($app) {
    $container = $app->getContainer();
    $response = $container->get('http_factory')->createResponse(200);
    $response->getBody()->write(
        $container->get('hello')->sayHello($name)
    );
    return $response;
});

// Register console commands (using: symfony/console)
// -----------------------------------------------------------------------------
$app->getConsole()->register('hello')
    ->setDescription('Say hello')
    ->addArgument('name', null, 'Your name', 'World')
    ->setCode(function($input, $output) use ($app) {
        $service = $app->getContainer()->get('hello');
        $output->writeLn($service->sayHello($input->getArgument('name')));
    });

// ...or using lazy command factories:
use Flow\Command\RequestCommand;
use Flow\Emitter\ConsoleEmitter;

$app->setConsoleCommandsLoader([
    'request' => function() use ($app) {
        return new RequestCommand(
            $app->getServerRequestCreator(),
            $app->getBroker(),
            new ConsoleEmitter
        );
    }
]);

return $app->run();
