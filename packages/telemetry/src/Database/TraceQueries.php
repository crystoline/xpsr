<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry\Database;

use Closure;
use Crystoline\Xpsr\Database\Event\QueryExecuted;
use Crystoline\Xpsr\Telemetry\Attributes;
use Crystoline\Xpsr\Telemetry\Tracer;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * A client span per database query, from the QueryExecuted event of crystoline/xpsr-database:
 * named "SELECT payments" (the operation and the first table), with the SQL as db.query.text
 * and no parameter values. The span is backdated by the query's duration.
 *
 * @internal Listens for QueryExecuted when the queries instrumentation is on.
 */
final class TraceQueries
{
    /** @var array<string, string> */
    private array $systems = [];

    /** @param Closure(string): ?string $system the driver of a connection name, from config/database.php */
    public function __construct(private readonly Tracer $tracer, private readonly Closure $system) {}

    public function __invoke(QueryExecuted $event): void
    {
        $sql = trim($event->sql);
        $operation = strtoupper(preg_match('/\A([A-Za-z]+)/', $sql, $match) === 1 ? $match[1] : 'QUERY');
        $table = preg_match('/\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+[`"\[]?([A-Za-z_][A-Za-z0-9_.]*)/i', $sql, $match) === 1 ? $match[1] : null;
        $attributes = [
            Attributes::DB_SYSTEM_NAME => $this->system($event->connection),
            Attributes::DB_OPERATION_NAME => $operation,
            Attributes::DB_QUERY_TEXT => $sql,
            Attributes::XPSR_DB_CONNECTION => $event->connection,
        ];
        if ($table !== null) {
            $attributes[Attributes::DB_COLLECTION_NAME] = $table;
        }
        $start = Clock::getDefault()->now() - (int) round($event->seconds * 1_000_000_000);
        $this->tracer->start($table === null ? $operation : "{$operation} {$table}", $attributes, SpanKind::KIND_CLIENT, startNanos: $start)->end();
    }

    private function system(string $connection): string
    {
        if (!isset($this->systems[$connection])) {
            $driver = strtolower((string) ($this->system)($connection));
            $this->systems[$connection] = match (true) {
                str_contains($driver, 'mysql') => 'mysql',
                str_contains($driver, 'maria') => 'mariadb',
                str_contains($driver, 'pgsql'), str_contains($driver, 'postgres') => 'postgresql',
                str_contains($driver, 'sqlite') => 'sqlite',
                str_contains($driver, 'sqlsrv'), str_contains($driver, 'mssql') => 'microsoft.sql_server',
                str_contains($driver, 'oci'), str_contains($driver, 'oracle') => 'oracle.db',
                str_contains($driver, 'db2'), str_contains($driver, 'ibm') => 'ibm.db2',
                default => 'other_sql',
            };
        }

        return $this->systems[$connection];
    }
}
