<?php

namespace Flow\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * Emit response to HTTP
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
class HttpEmitter implements EmitterInterface
{
    public function emit(ResponseInterface $response): void
    {
        $httpLine = sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        header($httpLine, true, $response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (!$stream->eof()) {
            echo $stream->read(1024 * 8);
        }
    }
}
