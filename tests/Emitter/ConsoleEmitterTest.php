<?php

namespace Tests\Emitter;

use Flow\Emitter\ConsoleEmitter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConsoleEmitterTest extends MockeryTestCase
{
    public function testEmit()
    {
        $output = new BufferedOutput();
        $emitter = new ConsoleEmitter();
        $emitter->setConsoleOutput($output);

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getProtocolVersion')->andReturn('1.1');
        $response->shouldReceive('getStatusCode')->andReturn(200);
        $response->shouldReceive('getReasonPhrase')->andReturn('OK');
        $response->shouldReceive('getHeaders')->andReturn([
            'date' => ['Thu, 20 Feb 2025 11:50:09 GMT']
        ]);
        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('__toString')->andReturn('Foo');
        $response->shouldReceive('getBody')->andReturn($stream);

        /** @disregard P1006 Expected type */
        $emitter->emit($response);

        $result = $output->fetch();

        $expected = <<<EOF
        HTTP/1.1 200 OK\r
        date: Thu, 20 Feb 2025 11:50:09 GMT\r

        Foo

        EOF;

        $this->assertEquals($expected, $result);
    }
}
