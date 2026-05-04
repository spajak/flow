<?php

namespace Tests\Command;

use Flow\Command\RequestCommand;
use Flow\Emitter\ConsoleEmitterInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class RequestCommandTest extends MockeryTestCase
{
    public function testExecuteReturns0ForSuccessResponse(): void
    {
        $command = $this->buildCommand($responseStatus = 200);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['method' => 'GET', 'uri' => '/']);

        $this->assertSame(0, $exit);
    }

    public function testExecuteReturns1For4xx(): void
    {
        $command = $this->buildCommand(404);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['method' => 'GET', 'uri' => '/missing']);

        $this->assertSame(1, $exit);
    }

    public function testExecuteReturns2For5xx(): void
    {
        $command = $this->buildCommand(503);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['method' => 'GET', 'uri' => '/broken']);

        $this->assertSame(2, $exit);
    }

    public function testGetResponseDateFallsBackToCurrentTime(): void
    {
        $command = $this->buildCommand(200);

        $reflection = new \ReflectionMethod($command, 'getResponseDate');
        $before = time();
        $date = $reflection->invoke($command);
        $after = time();

        $this->assertNotEmpty($date);
        $parsed = strtotime($date);
        $this->assertNotFalse($parsed);
        $this->assertGreaterThanOrEqual($before, $parsed);
        $this->assertLessThanOrEqual($after, $parsed);
    }

    public function testGetResponseDateUsesInjectedTimeWhenSet(): void
    {
        $command = $this->buildCommand(200);
        $command->setResponseTime($fixed = 1_700_000_000);

        $reflection = new \ReflectionMethod($command, 'getResponseDate');
        $date = $reflection->invoke($command);

        $this->assertSame(gmdate('D, d M Y H:i:s T', $fixed), $date);
    }

    public function testParseUrlExtractsPathAndQuery(): void
    {
        $command = $this->buildCommand(200);

        $reflection = new \ReflectionMethod($command, 'parseUrl');
        [$uri, $query] = $reflection->invoke($command, '/items/42?foo=bar&baz=1');

        $this->assertSame('/items/42?foo=bar&baz=1', $uri);
        $this->assertSame('foo=bar&baz=1', $query);
    }

    public function testParseUrlReturnsRootForEmptyPath(): void
    {
        $command = $this->buildCommand(200);

        $reflection = new \ReflectionMethod($command, 'parseUrl');
        [$uri, $query] = $reflection->invoke($command, '');

        $this->assertSame('/', $uri);
        $this->assertNull($query);
    }

    public function testParseUrlReturnsFallbackForMalformedUrl(): void
    {
        // parse_url returns false for inputs like "http://:80" (port without
        // host). The fallback path returns ['/', null] so the command can
        // still proceed and the request creator gets sane defaults.
        $command = $this->buildCommand(200);

        $reflection = new \ReflectionMethod($command, 'parseUrl');
        [$uri, $query] = $reflection->invoke($command, 'http://:80');

        $this->assertSame('/', $uri);
        $this->assertNull($query);
    }

    public function testExecuteEmitsResponseWithDateAndServerHeaders(): void
    {
        // Locks the header-decoration logic in execute(): the response that
        // reaches the emitter MUST carry a `date` header (RFC 2616 format)
        // and a `server: Flow` header. Without this test the decoration is
        // invisible — buildCommand's lenient mocks would let breakage slide.
        $factory = new Psr17Factory();
        $serverRequest = $factory->createServerRequest('GET', '/');
        $response = $factory->createResponse(200);

        $serverRequestCreator = $this->createStub(ServerRequestCreatorInterface::class);
        $serverRequestCreator->method('fromArrays')->willReturn($serverRequest);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $captured = null;
        $emitter = Mockery::mock(ConsoleEmitterInterface::class);
        $emitter->shouldReceive('setConsoleOutput')->once();
        $emitter->shouldReceive('emit')
            ->once()
            ->with(Mockery::on(function (ResponseInterface $r) use (&$captured): bool {
                $captured = $r;
                return true;
            }));

        /** @disregard P1006 Expected type */
        $command = new RequestCommand($serverRequestCreator, $handler, $emitter);
        $command->setResponseTime($fixed = 1_700_000_000);

        $tester = new CommandTester($command);
        $tester->execute(['method' => 'GET', 'uri' => '/']);

        $this->assertNotNull($captured);
        $this->assertSame(gmdate('D, d M Y H:i:s T', $fixed), $captured->getHeaderLine('date'));
        $this->assertSame('Flow', $captured->getHeaderLine('server'));
    }

    public function testMarshalGlobalsBuildsCanonicalServerArray(): void
    {
        $command = $this->buildCommand(200);

        $input = Mockery::mock(\Symfony\Component\Console\Input\InputInterface::class);
        $input->shouldReceive('getArgument')->with('method')->andReturn('post');
        $input->shouldReceive('getArgument')->with('uri')->andReturn('/x?a=1');

        $reflection = new \ReflectionMethod($command, 'marshalGlobals');
        /** @var array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>, 3: array<string, mixed>, 4: array<string, mixed>, 5: array<string, mixed>} $globals */
        $globals = $reflection->invoke($command, $input);
        [$server, $headers, $cookie, $get, $post, $files] = $globals;

        $this->assertSame('POST', $server['REQUEST_METHOD']);
        $this->assertSame('/x?a=1', $server['REQUEST_URI']);
        $this->assertSame('a=1', $server['QUERY_STRING']);
        $this->assertSame('console.in', $server['HTTP_HOST']);
        $this->assertSame(['host' => 'console.in'], $headers);
        $this->assertSame([], $cookie);
        $this->assertSame(['a' => '1'], $get);
        $this->assertSame([], $post);
        $this->assertSame([], $files);
    }

    private function buildCommand(int $responseStatus): RequestCommand
    {
        $serverRequest = $this->createStub(ServerRequestInterface::class);

        $serverRequestCreator = $this->createStub(ServerRequestCreatorInterface::class);
        $serverRequestCreator->method('fromArrays')->willReturn($serverRequest);

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('withHeader')->andReturn($response);
        $response->shouldReceive('getStatusCode')->andReturn($responseStatus);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        // Permissive: reflection-only tests (parseUrl, getResponseDate, etc.)
        // share this builder and never call execute(), so emit/setConsoleOutput
        // may legitimately be invoked zero times. testExecuteEmitsResponseWith
        // DateAndServerHeaders has its own strict setup for verifying emit().
        $emitter = Mockery::mock(ConsoleEmitterInterface::class);
        $emitter->shouldReceive('setConsoleOutput')->andReturnNull();
        $emitter->shouldReceive('emit')->andReturnNull();

        /** @disregard P1006 Expected type */
        return new RequestCommand($serverRequestCreator, $handler, $emitter);
    }
}
