<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Telemetry;

/**
 * The attribute names the package sets, from the OpenTelemetry semantic conventions (HTTP,
 * database, and messaging), plus XPSR's own under "xpsr.". Named here, so a renaming in the
 * conventions is one change.
 */
final class Attributes
{
    public const HTTP_REQUEST_METHOD = 'http.request.method';
    public const HTTP_RESPONSE_STATUS_CODE = 'http.response.status_code';
    public const HTTP_ROUTE = 'http.route';
    public const URL_FULL = 'url.full';
    public const URL_PATH = 'url.path';
    public const URL_QUERY = 'url.query';
    public const URL_SCHEME = 'url.scheme';
    public const SERVER_ADDRESS = 'server.address';
    public const SERVER_PORT = 'server.port';
    public const CLIENT_ADDRESS = 'client.address';
    public const USER_AGENT_ORIGINAL = 'user_agent.original';
    public const NETWORK_PROTOCOL_VERSION = 'network.protocol.version';
    public const ERROR_TYPE = 'error.type';

    public const DB_SYSTEM_NAME = 'db.system.name';
    public const DB_QUERY_TEXT = 'db.query.text';
    public const DB_OPERATION_NAME = 'db.operation.name';
    public const DB_COLLECTION_NAME = 'db.collection.name';

    public const MESSAGING_SYSTEM = 'messaging.system';
    public const MESSAGING_OPERATION_TYPE = 'messaging.operation.type';
    public const MESSAGING_DESTINATION_NAME = 'messaging.destination.name';

    /** The route's name, when it has one. */
    public const XPSR_ROUTE_NAME = 'xpsr.route.name';
    /** The request ID of crystoline/xpsr-log-monolog. */
    public const XPSR_REQUEST_ID = 'xpsr.request_id';
    /** The connection name of config/database.php. */
    public const XPSR_DB_CONNECTION = 'xpsr.db.connection';
    /** The job class. */
    public const XPSR_JOB = 'xpsr.job';
    /** The console command name. */
    public const XPSR_COMMAND = 'xpsr.command';
    /** The command's exit code. */
    public const XPSR_COMMAND_EXIT_CODE = 'xpsr.command.exit_code';
    /** The scheduled task's description. */
    public const XPSR_TASK = 'xpsr.task';
    /** The task's kind: command, call, or job. */
    public const XPSR_TASK_KIND = 'xpsr.task.kind';
    /** The task's result: done or failed. */
    public const XPSR_TASK_STATUS = 'xpsr.task.status';
}
