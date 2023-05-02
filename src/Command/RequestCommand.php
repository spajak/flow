<?php

namespace Flow\Command;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Psr\Http\Server\RequestHandlerInterface;
use Flow\Emitter\ConsoleEmitterInterface;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;

/**
 * Symfony Console command to run requests in terminal.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
class RequestCommand extends SymfonyCommand
{
    protected $serverRequestCreator;
    protected $handler;
    protected $emitter;
    protected static $host = 'console.in';

    public function __construct(
        ServerRequestCreatorInterface $serverRequestCreator,
        RequestHandlerInterface $handler,
        ConsoleEmitterInterface $emitter
    ) {
        parent::__construct();
        $this->serverRequestCreator = $serverRequestCreator;
        $this->handler = $handler;
        $this->emitter = $emitter;
    }

    protected function configure()
    {
        $this->setName('request')
            ->setDescription('Fire request in console')
            ->addArgument('method', InputArgument::REQUIRED, 'HTTP method')
            ->addArgument('uri', InputArgument::REQUIRED, 'URI');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $globals = $this->marshalGlobals($input);
        $request = $this->serverRequestCreator->fromArrays(...$globals);
        $response = $this->handler->handle($request);

        $this->emitter->setConsoleOutput($output);
        $this->emitter->emit(
            $response->withHeader('date', $this->getResponseDate())
        );

        return 0;
    }

    public function getResponseDate()
    {
        return gmdate('D, d M Y H:i:s T');
    }

    protected function marshalGlobals(InputInterface $input): array
    {
        [$uri, $query] = $this->parseUrl($input->getArgument('uri'));

        $server = [
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => self::$host,
            'REQUEST_METHOD' => strtoupper($input->getArgument('method')),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $query,
            'HTTPS' => null,
            'HTTP_HOST' => self::$host,
            'HTTP_USER_AGENT' => 'Console',
        ];

        $headers = ['host' => self::$host];
        $cookie = [];
        $get = $this->parseQuery($query ?? '');
        $post = [];
        $files = [];

        return [$server, $headers, $cookie, $get, $post, $files];
    }

    protected function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        $uri = $parts['path'] ?? '/';
        $query = $parts['query'] ?? null;
        if ($query) {
            $uri .= '?'.$query;
        }
        return [$uri, $query];
    }

    protected function parseQuery(string $query): array
    {
        $result = [];
        parse_str($query, $result);
        return $result;
    }
}
