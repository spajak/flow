<?php

namespace Flow\Emitter;

use Psr\Http\Message\ResponseInterface;

use RuntimeException;

/**
 * Emit response to HTTP.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
final class HttpEmitter implements EmitterInterface
{
    public function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf(
                'Cannot emit response: headers already sent (output started at %s:%d).',
                $file !== '' ? $file : 'unknown',
                $line
            ));
        }

        $statusCode = $response->getStatusCode();

        $httpLine = sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $statusCode,
            $response->getReasonPhrase()
        );
        header($httpLine, true, $statusCode);

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        $stream = $response->getBody();

        // Add Content-Length when the stream knows its size and the response
        // didn't already declare it. Lets nginx reuse keep-alive connections.
        if (!$response->hasHeader('Content-Length')) {
            $size = $stream->getSize();
            if ($size !== null) {
                header('Content-Length: '.$size, false);
            }
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (!$stream->eof()) {
            echo $stream->read(1024 * 8);
        }

        // Free the FastCGI worker so post-response work (logging, async tasks,
        // session save) doesn't keep the client waiting.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
