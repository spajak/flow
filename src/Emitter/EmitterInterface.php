<?php

namespace Flow\Emitter;

use Psr\Http\Message\ResponseInterface;

/**
 * Response emitter.
 */
interface EmitterInterface
{
    /**
     * Emit (write) response to an output.
     */
    public function emit(ResponseInterface $response): void;
}
