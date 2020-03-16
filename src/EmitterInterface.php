<?php

namespace Flow;

use Psr\Http\Message\ResponseInterface;

/**
 * Response emitter.
 */
interface EmitterInterface
{
    /**
     * Emit (write) reponse to an output.
     */
    public function emit(ResponseInterface $reponse): void;
}
