<?php

namespace Flow\Emitter;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emit response to console output.
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
final class ConsoleEmitter implements ConsoleEmitterInterface
{
    private OutputInterface $output;

    public function __construct(?OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput();
    }

    public function setConsoleOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function emit(ResponseInterface $response): void
    {
        $this->emitStatusLine($response);
        $this->emitHeaders($response);
        $this->emitBody($response);
    }

    private function emitStatusLine(ResponseInterface $response): void
    {
        $this->output->writeln(sprintf(
            '<fg=green>HTTP/%s %s %s</>',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ));
    }

    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $this->output->writeln(sprintf('<fg=yellow>%s: %s</>', $name, $value));
            }
        }
    }

    private function emitBody(ResponseInterface $response): void
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $this->output->write("\n");
        while (!$stream->eof()) {
            $this->output->write($stream->read(1024 * 8), false, OutputInterface::OUTPUT_RAW);
        }
        $this->output->write("\n");
    }
}
