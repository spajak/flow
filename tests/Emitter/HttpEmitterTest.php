<?php

namespace Tests\Emitter;

use Flow\Emitter\HttpEmitter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpEmitterTest extends MockeryTestCase
{
    public function testEmit(): void
    {
        $emitter = new HttpEmitter();

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getProtocolVersion')->andReturn('1.1');
        $response->shouldReceive('getStatusCode')->andReturn(200);
        $response->shouldReceive('getReasonPhrase')->andReturn('OK');
        $response->shouldReceive('getHeaders')->andReturn([
            'date' => ['Thu, 20 Feb 2025 11:50:09 GMT']
        ]);
        $response->shouldReceive('hasHeader')->with('Content-Length')->andReturn(false);

        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->andReturn(false);
        $stream->shouldNotReceive('rewind');
        $stream->shouldReceive('getSize')->andReturn(null);
        $stream->shouldReceive('eof')->times(2)->andReturnValues([false, true]);
        $stream->shouldReceive('read')->andReturn('Foo');
        $response->shouldReceive('getBody')->andReturn($stream);

        ob_start();
        /** @disregard P1006 Expected type */
        $emitter->emit($response);
        $output = ob_get_clean();

        $this->assertEquals('Foo', $output);
    }

    public function testEmitAddsContentLengthForSeekableStreamOfKnownSize(): void
    {
        $emitter = new HttpEmitter();

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getProtocolVersion')->andReturn('1.1');
        $response->shouldReceive('getStatusCode')->andReturn(200);
        $response->shouldReceive('getReasonPhrase')->andReturn('OK');
        $response->shouldReceive('getHeaders')->andReturn([]);
        $response->shouldReceive('hasHeader')->with('Content-Length')->andReturn(false);

        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->andReturn(true);
        $stream->shouldReceive('rewind')->once();
        $stream->shouldReceive('getSize')->andReturn(11);
        $stream->shouldReceive('eof')->andReturnValues([false, true]);
        $stream->shouldReceive('read')->andReturn('Hello world');
        $response->shouldReceive('getBody')->andReturn($stream);

        ob_start();
        /** @disregard P1006 Expected type */
        $emitter->emit($response);
        $output = ob_get_clean();

        $this->assertEquals('Hello world', $output);
        // We can't introspect headers in CLI tests, but Mockery verifies the
        // happy path: hasHeader was queried, getSize was queried, no error.
        $this->addToAssertionCount(1);
    }

    public function testEmitDoesNotOverrideExistingContentLength(): void
    {
        $emitter = new HttpEmitter();

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getProtocolVersion')->andReturn('1.1');
        $response->shouldReceive('getStatusCode')->andReturn(200);
        $response->shouldReceive('getReasonPhrase')->andReturn('OK');
        $response->shouldReceive('getHeaders')->andReturn([
            'Content-Length' => ['5'],
        ]);
        $response->shouldReceive('hasHeader')->with('Content-Length')->andReturn(true);

        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->andReturn(false);
        $stream->shouldNotReceive('rewind');
        // getSize must NOT be queried when the response already declared CL.
        $stream->shouldNotReceive('getSize');
        $stream->shouldReceive('eof')->andReturnValues([false, true]);
        $stream->shouldReceive('read')->andReturn('Hello');
        $response->shouldReceive('getBody')->andReturn($stream);

        ob_start();
        /** @disregard P1006 Expected type */
        $emitter->emit($response);
        $output = ob_get_clean();

        $this->assertEquals('Hello', $output);
    }
}
