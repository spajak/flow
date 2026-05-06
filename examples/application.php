<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new Flow\Application();

// Register services (using: php-di/php-di)
// -----------------------------------------------------------------------------
$services = [];
$services['hello'] = fn () => new class {
    public function sayHello($name) { return "Hello {$name}!"; }
};
$app->getContainerBuilder()->addDefinitions($services);

// Register routes (using: nikic/fast-route)
// -----------------------------------------------------------------------------
$app->getRouteCollector()->get('/hello[/{name}]', function ($request, $name = 'World') use ($app) {
    $container = $app->getContainer();
    $response = $container->get('http_factory')->createResponse(200);
    $response->getBody()->write(
        $container->get('hello')->sayHello($name)
    );
    return $response;
});

// Register console commands (using: symfony/console)
// -----------------------------------------------------------------------------
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

$app->getConsole()->register('hello')
    ->setDescription('Say hello')
    ->addArgument('name', null, 'Your name', 'World')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($app) {
        $service = $app->getContainer()->get('hello');
        $output->writeLn($service->sayHello($input->getArgument('name')));
        return 0;
    });

// ...or use factories to lazy-load commands:
use Flow\Command\RequestCommand;
use Flow\Emitter\ConsoleEmitter;

$commands = [];
$commands['request'] = fn () => new RequestCommand(
    $app->getServerRequestCreator(),
    $app->getBroker(),
    new ConsoleEmitter()
);
$app->getCommandLoader()->addFactories($commands);

return $app->run();
