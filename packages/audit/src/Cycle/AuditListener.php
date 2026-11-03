<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Audit\Cycle;

use Crystoline\Xpsr\Audit\AuditKey;
use Crystoline\Xpsr\Audit\AuditLog;
use Crystoline\Xpsr\Audit\AuditOptions;
use Crystoline\Xpsr\Audit\Changes;
use Crystoline\Xpsr\Audit\Keys;
use Cycle\ORM\Command\Special\WrappedCommand;
use Cycle\ORM\Command\Special\WrappedStoreCommand;
use Cycle\ORM\Command\StoreCommandInterface;
use Cycle\ORM\Entity\Behavior\Attribute\Listen;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnCreate;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnDelete;
use Cycle\ORM\Entity\Behavior\Event\Mapper\Command\OnUpdate;
use Cycle\ORM\Entity\Behavior\Event\MapperEvent;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Records Cycle entities marked #[Audited] as they are inserted, updated, and deleted, once each
 * command has run and inside the same transaction: created with every field, updated with the
 * fields that changed, deleted with the fields that were there. Attached to an entity's role by
 * AuditedBehavior; built by Cycle from the container, with the attribute's except and redact.
 *
 * The callbacks are static closures: Cycle runs them unbound.
 *
 * @internal
 */
final class AuditListener
{
    /**
     * @param list<string> $except
     * @param list<string> $redact
     */
    public function __construct(
        private readonly AuditLog $log,
        private readonly AuditOptions $options,
        private readonly ContainerInterface $container,
        private readonly array $except = [],
        private readonly array $redact = [],
    ) {}

    #[Listen(OnCreate::class)]
    public function created(OnCreate $event): void
    {
        $command = $event->command;
        if (!$command instanceof StoreCommandInterface) {
            return;
        }
        [$log, $container, $except, $redact] = [$this->log, $this->container, $this->except(), $this->redact()];
        $event->command = WrappedStoreCommand::wrapStoreCommand($command)->withAfterExecution(static function () use ($event, $log, $container, $except, $redact): void {
            $fields = $event->state->getData();
            $log->record('created', AuditListener::keyOf($container, $event, $fields), Changes::between([], $fields, $except, $redact));
        });
    }

    #[Listen(OnUpdate::class)]
    public function updated(OnUpdate $event): void
    {
        $command = $event->command;
        if (!$command instanceof StoreCommandInterface) {
            return;
        }
        $after = $event->state->getChanges();
        $before = array_intersect_key($event->node->getData(), $after);
        $changes = Changes::between($before, $after, $this->except(), $this->redact());
        if ($changes === []) {
            return;
        }
        [$log, $container] = [$this->log, $this->container];
        $event->command = WrappedStoreCommand::wrapStoreCommand($command)->withAfterExecution(static function () use ($event, $changes, $log, $container): void {
            $log->record('updated', AuditListener::keyOf($container, $event, $event->state->getData()), $changes);
        });
    }

    #[Listen(OnDelete::class)]
    public function deleted(OnDelete $event): void
    {
        $command = $event->command;
        if ($command === null) {
            return;
        }
        $fields = $event->node->getData();
        $key = self::keyOf($this->container, $event, $fields);
        $changes = Changes::between($fields, [], $this->except(), $this->redact());
        $log = $this->log;
        $event->command = WrappedCommand::wrapCommand($command)->withAfterExecution(static function () use ($log, $key, $changes): void {
            $log->record('deleted', $key, $changes);
        });
    }

    /**
     * @internal The entity's audit key: its class and primary key, as the role's schema names it.
     *
     * @param array<string, mixed> $fields
     */
    public static function keyOf(ContainerInterface $container, MapperEvent $event, array $fields): AuditKey
    {
        $primary = $container->get(ORMInterface::class)->getSchema()->define($event->role, SchemaInterface::PRIMARY_KEY);
        $ids = [];
        foreach (is_array($primary) ? $primary : [$primary] as $field) {
            $id = is_string($field) ? ($fields[$field] ?? null) : null;
            if ($id === null) {
                throw new InvalidArgumentException(sprintf('The %s entity has no primary key value, so it cannot be audited.', $event->entity::class));
            }
            $ids[] = Keys::id($id);
        }

        return new AuditKey($event->entity::class, implode(':', $ids));
    }

    /** @return list<string> */
    private function except(): array
    {
        return [...$this->options->except, ...$this->except];
    }

    /** @return list<string> */
    private function redact(): array
    {
        return [...$this->options->redact, ...$this->redact];
    }
}
