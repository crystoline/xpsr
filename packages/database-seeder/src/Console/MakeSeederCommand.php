<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Seeder\Console;

use Crystoline\Xpsr\Console\Commands\AbstractMakeCommand;
use Crystoline\Xpsr\Console\Generator\GeneratedClass;
use Crystoline\Xpsr\Console\Generator\StubGenerator;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\Database\Seeder\TableShape;
use DateTimeInterface;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\ConversionException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a seeder class in app/Seed. With --dump, the rows a table holds now are written into
 * it, which turns the reference data of an existing database into code.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class MakeSeederCommand extends AbstractMakeCommand
{
    /** --dump is for reference data, which is small; a table above this is refused. */
    private const MAX_ROWS = 1000;

    private ?int $dumped = null;

    /** @var array<string, string> the columns --dump left out, by name */
    private array $skipped = [];

    public function __construct(StubGenerator $generator, private readonly ContainerInterface $container)
    {
        parent::__construct($generator->withStubDirectory(dirname(__DIR__) . '/Resources/stubs'), 'make:seeder');
    }

    protected function kind(): string
    {
        return 'seeder';
    }

    protected function subNamespace(): string
    {
        return 'Seed';
    }

    protected function example(): string
    {
        return 'RolesSeeder';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('table', null, InputOption::VALUE_REQUIRED, 'The table the seeder fills. Defaults to the class name: RolesSeeder fills roles.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The column that identifies a row, such as slug; several separated by commas. With --dump it defaults to the primary key.')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Write the rows the table holds now into the seeder. Needs --table.')
            ->addOption('except', null, InputOption::VALUE_REQUIRED, 'With --dump: columns to leave out, separated by commas, such as created_at,updated_at.')
            ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'With --dump: the connection to read from. Defaults to database.default.');
    }

    protected function replacements(GeneratedClass $target, InputInterface $input, SymfonyStyle $io): array
    {
        $this->dumped = null;
        $this->skipped = [];
        $table = $input->getOption('table');
        $keys = self::names($input->getOption('key'));
        if ($input->getOption('dump')) {
            if (!is_string($table) || $table === '') {
                throw new InvalidArgumentException('--dump needs --table: the table whose rows to write.');
            }
            $connection = $input->getOption('connection');
            try {
                [$keys, $rows] = $this->dump($table, $keys, self::names($input->getOption('except')), is_string($connection) && $connection !== '' ? $connection : null);
            } catch (DbalException $exception) {
                throw new InvalidArgumentException("Could not read {$table}: {$exception->getMessage()}", previous: $exception);
            }

            return ['table' => $table, 'key' => self::keyExpression($keys), 'rows' => $rows];
        }
        if (!is_string($table) || $table === '') {
            $table = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) preg_replace('/Seeder\z/', '', $target->shortName)));
        }
        $keys = $keys === [] ? ['slug'] : $keys;
        $example = implode(', ', array_map(static fn(string $column): string => "'{$column}' => ...", $keys));

        return ['table' => $table, 'key' => self::keyExpression($keys), 'rows' => "            // [{$example}, 'name' => ...],"];
    }

    protected function nextSteps(GeneratedClass $target, array $replacements, InputInterface $input): array
    {
        $steps = [];
        if ($this->dumped !== null) {
            $steps[] = "Wrote {$this->dumped} row(s) of {$replacements['table']}.";
        }
        if ($this->skipped !== []) {
            $steps[] = 'Left out, because their values are binary: ' . implode(', ', $this->skipped) . '.';
        }

        return [
            ...$steps,
            'Next: list it in a set in config/seeds.php, then see what it would change with php xpsr seed --dry-run:',
            '    \'sets\' => [\'reference\' => [\\' . $target->className() . '::class]],',
        ];
    }

    /**
     * The rows of $table as PHP source, one per line, with the key they are matched by.
     *
     * @param list<string> $keys
     * @param list<string> $except
     * @return array{list<string>, string}
     */
    private function dump(string $table, array $keys, array $except, ?string $connection): array
    {
        $db = $this->container->get(ConnectionManager::class)->connection($connection);
        $shape = TableShape::of($db, $table);
        // With the primary key as the key, rows keep their IDs, which other tables refer to.
        $keys = $keys === [] ? $shape->primaryKey : $keys;
        if ($keys === []) {
            throw new InvalidArgumentException("--key is needed: the primary key of {$table} is not known.");
        }
        $count = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $shape->sql);
        if ($count > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('%s has %d rows. --dump is for small tables of reference data, up to %d rows.', $table, $count, self::MAX_ROWS));
        }
        // An ID the database counts by itself is left out, unless rows are matched by it.
        $leaveOut = [...$except, ...array_diff($shape->autoIncrement, $keys)];
        $order = implode(', ', array_map($shape->column(...), $keys));
        $lines = [];
        foreach ($db->fetchAllAssociative("SELECT * FROM {$shape->sql} ORDER BY {$order}") as $row) {
            $values = [];
            foreach ($row as $column => $value) {
                if (in_array($column, $leaveOut, true)) {
                    continue;
                }
                $type = $shape->type($column);
                if ($type instanceof BooleanType && !in_array($value, [0, 1, '0', '1', true, false, 't', 'f', null], true)) {
                    // MySQL reads every TINYINT column as a boolean: a number in one stays the number.
                    $type = null;
                    $value = is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 ? (int) $value : $value;
                }
                if ($type !== null) {
                    try {
                        $value = $type->convertToPHPValue($value, $shape->platform);
                        // A date goes into the source as the database writes it.
                        $value = $value instanceof DateTimeInterface ? $type->convertToDatabaseValue($value, $shape->platform) : $value;
                    } catch (ConversionException) {
                        // Kept as the database gave it.
                    }
                }
                if (is_resource($value)) {
                    $this->skipped[$column] = $column;
                    continue;
                }
                $values[] = var_export($column, true) . ' => ' . self::export($value);
            }
            $lines[] = '            [' . implode(', ', $values) . '],';
        }
        $this->dumped = count($lines);

        return [$keys, implode("\n", $lines)];
    }

    /** @return list<string> */
    private static function names(mixed $option): array
    {
        return is_string($option) ? array_values(array_filter(array_map('trim', explode(',', $option)), static fn(string $name): bool => $name !== '')) : [];
    }

    /** @param list<string> $keys */
    private static function keyExpression(array $keys): string
    {
        return count($keys) === 1 ? var_export($keys[0], true) : '[' . implode(', ', array_map(static fn(string $key): string => var_export($key, true), $keys)) . ']';
    }

    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = (array_is_list($value) ? '' : var_export($key, true) . ' => ') . self::export($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => var_export($value, true),
            default => var_export((string) $value, true),
        };
    }
}
