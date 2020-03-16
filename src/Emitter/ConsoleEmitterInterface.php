<?php

namespace Flow\Emitter;

use Flow\EmitterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Response emitter using Console output
 */
interface ConsoleEmitterInterface extends EmitterInterface
{
    /**
     * Set Console output to use.
     */
    public function setConsoleOutput(OutputInterface $output): void;
}
