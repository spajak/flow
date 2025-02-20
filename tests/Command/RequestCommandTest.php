<?php

namespace Tests\Command;

use Flow\Command\RequestCommand;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Flow\Emitter\ConsoleEmitterInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

final class RequestCommandTest extends MockeryTestCase
{
    public function testExecute()
    {
        $now = time();
        $date = gmdate('D, d M Y H:i:s T', $now);

        $input = Mockery::mock(InputInterface::class);
        $input->shouldReceive('getArgument')->with('uri')->andReturn('/test');
        $input->shouldReceive('getArgument')->with('method')->andReturn('GET');

        $output = new BufferedOutput();

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('withHeader')->with('date', $date)->andReturn($response);
        $response->shouldReceive('withHeader')->with('server', 'Flow')->andReturn($response);

        $serverRequest = $this->createMock(ServerRequestInterface::class);
        $serverRequestCreator = $this->createMock(ServerRequestCreatorInterface::class);
        $serverRequestCreator->method('fromArrays')->willReturn($serverRequest);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->with($serverRequest)->willReturn($response);

        $emitter = Mockery::mock(ConsoleEmitterInterface::class);
        $emitter->shouldReceive('setConsoleOutput')->once()->with($output);
        $emitter->shouldReceive('emit')->once()->with($response);

        /** @disregard P1006 Expected type */
        $requestCommand = new RequestCommand($serverRequestCreator, $handler, $emitter);
        $requestCommand->setResponseTime($now);

        $executeMethod = function ($input, $output) {
            /** @disregard P1013 Undefined method */
            return $this->execute($input, $output);
        };
        $result = $executeMethod->call($requestCommand, $input, $output);
        $content = $output->fetch();

        $this->assertEquals(0, $result);
        $this->assertEquals('', $content);
    }
}
