<?php

declare(strict_types=1);

namespace Crystoline\Console\Commands;

use Closure;
use Crystoline\Routing\RouteCollection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RouteListCommand extends Command
{
    public function __construct(private readonly RouteCollection $routes)
    {
        parent::__construct('route:list');
    }

    protected function configure(): void
    {
        $this->setDescription('List portable route definitions.')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Filter by a declared HTTP method.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Filter paths containing this text.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Filter names containing this text.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $method = $input->getOption('method');
        if ($method !== null && (!is_string($method) || !preg_match('/\A[A-Za-z]+\z/', $method))) {
            (new SymfonyStyle($input, $output))->getErrorStyle()->error('The method filter must contain only letters.');
            return self::INVALID;
        }
        $path = $input->getOption('path');
        $name = $input->getOption('name');
        $rows = [];
        foreach ($this->routes->all() as $route) {
            if ($method !== null && !in_array(strtoupper($method), $route->methods, true)) {
                continue;
            }
            if ($path !== null && !str_contains($route->path, $path)) {
                continue;
            }
            if ($name !== null && !str_contains($route->getName() ?? '', $name)) {
                continue;
            }
            $rows[] = [
                'methods' => $route->methods,
                'path' => $route->path,
                'name' => $route->getName(),
                'handler' => $this->describe($route->handler),
                'middleware' => array_map($this->describe(...), $route->getMiddleware()),
            ];
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = array_map(OutputFormatter::escape(...), [
                implode('|', $row['methods']), $row['path'], $row['name'] ?? '-',
                $row['handler'], implode(', ', $row['middleware']),
            ]);
        }
        (new Table($output))->setHeaders(['Methods', 'Path', 'Name', 'Handler', 'Middleware'])->setRows($table)->render();
        $output->writeln(count($rows) . ' route(s). Methods shown are declared; HEAD fallback and automatic OPTIONS are implicit.');
        return self::SUCCESS;
    }

    private function describe(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }
        if ($handler instanceof Closure) {
            return 'Closure';
        }
        if (is_array($handler)) {
            return $this->describe($handler[0]) . '::' . $handler[1];
        }
        return get_debug_type($handler);
    }
}
