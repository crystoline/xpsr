<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Crystoline\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class ServeCommand extends Command implements SignalableCommandInterface
{
    private ?Process $process = null;
    private ?int $interruptedExitCode = null;

    public function __construct(private readonly Application $application)
    {
        parent::__construct('serve');
    }

    protected function configure(): void
    {
        $this->setDescription('Run the PHP development server with public/ as its document root.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Bind address: localhost, IPv4, or IPv6.', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'TCP port (1-65535).', '3001');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $port = $input->getOption('port');
        $io = new SymfonyStyle($input, $output);
        if (!is_string($host) || ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false)) {
            $io->getErrorStyle()->error('Host must be localhost or an IPv4/IPv6 address.');
            return self::INVALID;
        }
        if (!is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $io->getErrorStyle()->error('Port must be an integer between 1 and 65535.');
            return self::INVALID;
        }
        $public = $this->application->getRootDir() . '/public';
        if (!is_file($public . '/index.php')) {
            $io->getErrorStyle()->error('The application needs a public/index.php front controller.');
            return self::FAILURE;
        }
        if (DIRECTORY_SEPARATOR === '/' && !function_exists('pcntl_signal')) {
            $io->getErrorStyle()->error('The serve command needs the pcntl extension on Unix to stop its child server cleanly.');
            return self::FAILURE;
        }
        $address = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . (int) $port;
        $this->interruptedExitCode = null;
        $this->process = new Process(
            [PHP_BINARY, '-S', $address, '-t', $public, dirname(__DIR__) . '/Resources/server.php'],
            $this->application->getRootDir(),
            ['PHP_CLI_SERVER_WORKERS' => false],
            timeout: null,
        );
        $output->writeln("Starting development server at http://{$address}. Press Ctrl+C to stop.", OutputInterface::OUTPUT_RAW);
        return $this->waitForServer($this->process, $output);
    }

    private function waitForServer(Process $process, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $status = $process->run(static function (string $type, string $buffer) use ($process, $output, $errorOutput): void {
                ($type === Process::ERR ? $errorOutput : $output)->write($buffer, false, OutputInterface::OUTPUT_RAW);
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            return $this->interruptedExitCode ?? $status;
        } catch (ProcessSignaledException $exception) {
            if ($this->interruptedExitCode === null) {
                throw $exception;
            }
            return $this->interruptedExitCode;
        } finally {
            $process->stop(1);
            $this->process = null;
        }
    }

    public function getSubscribedSignals(): array
    {
        return function_exists('pcntl_signal') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interruptedExitCode = 128 + $signal;
        $this->process?->stop(1);
        // Let execute() return the status. Do not let Console exit the host process.
        return false;
    }
}
