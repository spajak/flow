<?php

namespace Flow\Console\CommandLoader;

use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

class LazyCommandLoader implements CommandLoaderInterface
{
    private array $factories;

    /**
     * @param callable[] $factories Indexed by command names
     */
    public function __construct(array $factories = [])
    {
        $this->factories = $factories;
    }

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

        $factory = $this->factories[$name];

        return $factory();
    }

    public function getNames(): array
    {
        return array_keys($this->factories);
    }
}
