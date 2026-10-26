<?php

declare(strict_types=1);

namespace Tests\Fixtures\Console;

use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class BufferedConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct(decorated: false);
        $this->stderr = new BufferedOutput(decorated: false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }
    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function errors(): string
    {
        if (!$this->stderr instanceof BufferedOutput) {
            throw new LogicException('Error output is not buffered.');
        }
        return $this->stderr->fetch();
    }

    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('This test output does not support interactive sections.');
    }
}
