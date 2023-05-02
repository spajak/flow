
# Flow

Simple PHP HTTP application base using:

- PSR-7 HTTP Message / PSR-17 HTTP Factories: [nyholm/psr7](https://github.com/Nyholm/psr7)
- PSR-15 HTTP Handlers / Middleware: [northwoods/broker](https://github.com/northwoods/broker)
- PSR-11: Container: [php-di/php-di](https://github.com/PHP-DI/PHP-DI)
- Routing: [nikic/fast-route](https://github.com/nikic/FastRoute)
- Console: [symfony/console](https://github.com/symfony/console)

## Rationale

- Basing on standardized interfaces and well tested components;
- Not being tied to any specific framework;
- Being able to make lightweight and customizable apps fast with just PHP's `include`s and anonymous functions.

## Usage

```php
$app = new Flow\Application;
```

Register services (using: [php-di/php-di](https://github.com/PHP-DI/PHP-DI)):

```php
$services = [];
$services['hello'] = function() {
    return new class {
        public function sayHello($name) { return "Hello {$name}!"; }
    };
};
$app->getContainerBuilder()->addDefinitions($services);
```

Register routes (using: [nikic/fast-route](https://github.com/nikic/FastRoute)):

```php
$app->getRouteCollector()->get('/hello[/{name}]', function($request, $name = 'World') use ($app) {
    $container = $app->getContainer();
    $response = $container->get('http_factory')->createResponse(200);
    $response->getBody()->write(
        $container->get('hello')->sayHello($name)
    );
    return $response;
});
```

Register console commands (using: [symfony/console](https://github.com/symfony/console)):

```php
$app->getConsole()->register('hello')
    ->setDescription('Say hello')
    ->addArgument('name', null, 'Your name', 'World')
    ->setCode(function($input, $output) use ($app) {
        $service = $app->getContainer()->get('hello');
        $output->writeLn($service->sayHello($input->getArgument('name')));
    });
```

…or use factories to lazy-load commands:

```php
use Flow\Command\RequestCommand;
use Flow\Emitter\ConsoleEmitter;

$commands = [];
$commands['request'] = function() use ($app) {
    return new RequestCommand(
        $app->getServerRequestCreator(),
        $app->getBroker(),
        new ConsoleEmitter
    );
}
$app->getCommandLoader()->addFactories($commands);
```

At the end of the script, simply run the application:

```php
$app->run();
```

Try it from terminal:

```bash
$ php examples/application.php hello "Grim Reaper"
$ php examples/application.php request GET /hello
```

## License

MIT
