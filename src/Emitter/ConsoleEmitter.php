<?php

namespace Flow\Emitter;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\{OutputInterface, ConsoleOutput};

/**
 * Emit response to console output
 *
 * @author Sebastian Pająk <spconv@gmail.com>
 */
class ConsoleEmitter implements ConsoleEmitterInterface
{
    protected $output;

    public function __construct(OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput;
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
                $this->output->writeln(sprintf('%s: %s', $name, $value));
            }
        }
    }

    private function emitBody(ResponseInterface $response): void
    {
        $this->output->write("\n".$response->getBody()."\n");
    }
}
