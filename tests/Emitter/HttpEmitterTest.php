<?php

namespace Tests\Emitter;

use Flow\Emitter\HttpEmitter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpEmitterTest extends MockeryTestCase
{
    public function testEmit()
    {
        $emitter = new HttpEmitter();

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getProtocolVersion')->andReturn('1.1');
        $response->shouldReceive('getStatusCode')->times(2)->andReturn(200);
        $response->shouldReceive('getReasonPhrase')->andReturn('OK');
        $response->shouldReceive('getHeaders')->andReturn([
            'date' => ['Thu, 20 Feb 2025 11:50:09 GMT']
        ]);
        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->andReturn(false);
        $stream->shouldNotReceive('rewind');
        $stream->shouldReceive('eof')->times(2)->andReturnValues([false, true]);
        $stream->shouldReceive('read')->andReturn('Foo');
        $response->shouldReceive('getBody')->andReturn($stream);

        ob_start();
        /** @disregard P1006 Expected type */
        $emitter->emit($response);
        $output = ob_get_clean();

        $this->assertEquals('Foo', $output);
    }
}
