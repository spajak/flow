<?php

namespace Flow\Emitter;

use Flow\EmitterInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface ConsoleEmitterInterface extends EmitterInterface
{
    public function setConsoleOutput(OutputInterface $output): void;
}
