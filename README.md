# Flow

Simple PHP HTTP application base using:

- PSR-7 HTTP Message / PSR-17 HTTP Factories: [nyholm/psr7](https://github.com/Nyholm/psr7)
- PSR-15 HTTP Handlers / Middleware: [northwoods/broker](https://github.com/northwoods/broker)
- PSR-11: Container: [php-di/php-di](https://github.com/PHP-DI/PHP-DI)
- Routing: [nikic/fast-route](https://github.com/nikic/FastRoute)
- Console: [symfony/console](https://github.com/symfony/console)

## Rationale

- Make lightweight and customizable apps fast.
- Basing on standardized components.
- Not being tied to any specific framework.

## Usage

```php
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

$app->run();
```
## TODO

- Some kind of helper to simplify creation of the responses.
- Add logging capabilities.

## License

MIT
