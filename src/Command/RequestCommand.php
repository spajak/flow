<?php

namespace Flow\Command;

use Flow\Emitter\ConsoleEmitterInterface;

use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony Console command to run requests in terminal.
 *
 * Exit codes:
 *   0 — response status < 400
 *   1 — response status 400..499
 *   2 — response status >= 500 (or any thrown exception)
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
class RequestCommand extends SymfonyCommand
{
    protected const HOST = 'console.in';
    protected const SERVER = 'Flow';

    protected ?int $now = null;

    public function __construct(
        protected readonly ServerRequestCreatorInterface $serverRequestCreator,
        protected readonly RequestHandlerInterface $handler,
        protected readonly ConsoleEmitterInterface $emitter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('request')
            ->setDescription('Fire request in console')
            ->addArgument('method', InputArgument::REQUIRED, 'HTTP method')
            ->addArgument('uri', InputArgument::REQUIRED, 'URI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $globals = $this->marshalGlobals($input);
        $request = $this->serverRequestCreator->fromArrays(...$globals);
        $response = $this->handler->handle($request);

        $this->emitter->setConsoleOutput($output);
        $this->emitter->emit(
            $response
                ->withHeader('date', $this->getResponseDate())
                ->withHeader('server', self::SERVER)
        );

        $status = $response->getStatusCode();
        return match (true) {
            $status >= 500 => 2,
            $status >= 400 => 1,
            default => 0,
        };
    }

    public function setResponseTime(int $now): void
    {
        $this->now = $now;
    }

    /**
     * Return the current date in RFC 2616 format as HTTP response date.
     */
    protected function getResponseDate(): string
    {
        return gmdate('D, d M Y H:i:s T', $this->now ?? time());
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>, 3: array<string, mixed>, 4: array<string, mixed>, 5: array<string, mixed>}
     */
    protected function marshalGlobals(InputInterface $input): array
    {
        /** @var string $methodArg */
        $methodArg = $input->getArgument('method');
        /** @var string $uriArg */
        $uriArg = $input->getArgument('uri');

        [$uri, $query] = $this->parseUrl($uriArg);

        $server = [
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => self::HOST,
            'REQUEST_METHOD' => strtoupper($methodArg),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $query ?? '',
            'HTTP_HOST' => self::HOST,
            'HTTP_USER_AGENT' => 'Console',
        ];

        $headers = ['host' => self::HOST];
        $cookie = [];
        $get = $this->parseQuery($query ?? '');
        $post = [];
        $files = [];

        return [$server, $headers, $cookie, $get, $post, $files];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return ['/', null];
        }
        $uri = ($parts['path'] ?? '') ?: '/';
        $query = $parts['query'] ?? null;
        if ($query !== null && $query !== '') {
            $uri .= '?' . $query;
        }
        return [$uri, $query];
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function parseQuery(string $query): array
    {
        parse_str($query, $result);
        return $result;
    }
}
