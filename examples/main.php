<?php

require __DIR__.'/../vendor/autoload.php';

$app = new Flow\Application;

// Register services (using: php-di/php-di)
// -----------------------------------------------------------------------------
$app->getContainerBuilder()->addDefinitions([
    'hello' => function() {
        return new class { public function sayHello($name) {
            return "Hello {$name}!";
        }};
    }
]);

// Register routes (using: nikic/fast-route)
// -----------------------------------------------------------------------------
$app->getRouteCollector()->get('/hello[/{name}]', function($request, $name = 'World') use ($app) {
    $service = $app->getContainer()->get('hello');
    $response = $app->getHttpFactory()->createResponse(200);
    $response->getBody()->write($service->sayHello($name));
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

return $app->run();
