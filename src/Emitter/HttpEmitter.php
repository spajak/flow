<?php

namespace Flow\Emitter;

use Psr\Http\Message\ResponseInterface;
use function Http\Response\send;

/**
 * Emit response to HTTP
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
class HttpEmitter implements EmitterInterface
{
    public function emit(ResponseInterface $response): void
    {
        send($response);
    }
}
