<?php

namespace Flow\Console\CommandLoader;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class LazyCommandLoader implements CommandLoaderInterface
{
    /** @var array<string, callable(): Command> */
    private array $factories;

    /**
     * @param array<string, callable(): Command> $factories Indexed by command names.
     */
    public function __construct(array $factories = [])
    {
        $this->factories = $factories;
    }

    /**
     * @param array<string, callable(): Command> $factories
     */
    public function addFactories(array $factories): void
    {
        $this->factories = array_merge($this->factories, $factories);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    public function get(string $name): Command
    {
        if (!isset($this->factories[$name])) {
            throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
        }

        return ($this->factories[$name])();
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->factories);
    }
}
