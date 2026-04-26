<?php

namespace Tests\Console\CommandLoader;

use Flow\Console\CommandLoader\LazyCommandLoader;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class LazyCommandLoaderTest extends MockeryTestCase
{
    public function testHasReturnsTrueForRegisteredFactory(): void
    {
        $loader = new LazyCommandLoader([
            'foo' => fn (): Command => new Command('foo'),
        ]);

        $this->assertTrue($loader->has('foo'));
    }

    public function testHasReturnsFalseForUnknownName(): void
    {
        $loader = new LazyCommandLoader();

        $this->assertFalse($loader->has('does-not-exist'));
    }

    public function testGetInvokesFactoryAndReturnsCommand(): void
    {
        $command = new Command('hello');
        $invocations = 0;

        $loader = new LazyCommandLoader([
            'hello' => function () use ($command, &$invocations): Command {
                $invocations++;
                return $command;
            },
        ]);

        $this->assertSame($command, $loader->get('hello'));
        $this->assertSame(1, $invocations);
    }

    public function testGetThrowsCommandNotFoundExceptionForUnknownName(): void
    {
        $loader = new LazyCommandLoader();

        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessageMatches('/missing/');

        $loader->get('missing');
    }

    public function testGetNamesReturnsAllRegisteredKeys(): void
    {
        $loader = new LazyCommandLoader([
            'a' => fn (): Command => new Command('a'),
            'b' => fn (): Command => new Command('b'),
        ]);

        $this->assertSame(['a', 'b'], $loader->getNames());
    }

    public function testAddFactoriesMergesWithoutClobberingPriorEntries(): void
    {
        $loader = new LazyCommandLoader([
            'first' => fn (): Command => new Command('first'),
        ]);

        $loader->addFactories([
            'second' => fn (): Command => new Command('second'),
        ]);

        $this->assertTrue($loader->has('first'));
        $this->assertTrue($loader->has('second'));
        $this->assertSame(['first', 'second'], $loader->getNames());
    }

    public function testGetInvokesFactoryEveryTime(): void
    {
        // Documents current behaviour: the loader calls the factory on every
        // get() — it is a "lazy registration" not "lazy memoisation". If the
        // user wants singleton behaviour they should memoise inside the factory.
        $invocations = 0;
        $loader = new LazyCommandLoader([
            'cmd' => function () use (&$invocations): Command {
                $invocations++;
                return new Command('cmd');
            },
        ]);

        $loader->get('cmd');
        $loader->get('cmd');

        $this->assertSame(2, $invocations);
    }
}
