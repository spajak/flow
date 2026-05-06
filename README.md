# Flow

Simple PHP HTTP & CLI application base using:

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
$services['hello'] = fn() => new class {
    public function sayHello($name) { return "Hello {$name}!"; }
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

Register middleware (using: [northwoods/broker](https://github.com/northwoods/broker)):

```php
$app->getBroker()->append(new MyMiddleware);
```

…or defer construction until after bootstrap when the middleware needs services from the container:

```php
$app->getBroker()->appendDeferred(fn() => new MyMiddleware(
    $app->getContainer()->get('hello'),
));
```

Register console commands (using: [symfony/console](https://github.com/symfony/console)):

```php
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

$app->getConsole()->register('hello')
    ->setDescription('Say hello')
    ->addArgument('name', null, 'Your name', 'World')
    ->setCode(function(InputInterface $input, OutputInterface $output) use ($app) {
        $service = $app->getContainer()->get('hello');
        $output->writeLn($service->sayHello($input->getArgument('name')));
        return 0;
    });
```

…or use factories to lazy-load commands:

```php
use Flow\Command\RequestCommand;
use Flow\Emitter\ConsoleEmitter;

$commands = [];
$commands['request'] = fn() => new RequestCommand(
    $app->getServerRequestCreator(),
    $app->getBroker(),
    new ConsoleEmitter
);
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

## Lifecycle

Append all middleware to the broker *before* calling `$app->run()`. The router
middleware is appended last during bootstrap and terminates the pipeline on a
matched route, so anything appended afterwards is unreachable for matched
routes.

## Tests & static analysis

```bash
$ composer test       # phpunit
$ composer analyse    # phpstan
```

## License

MIT
