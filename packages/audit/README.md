# crystoline/xpsr-audit

An audit log for XPSR applications: who changed what, when, and from where, with the values before and after. Entries are recorded by hand where something happens, and automatically for Eloquent models and Doctrine entities that ask for it. Support, finance, and auditors read it; nothing in it is changed once written.

```sh
composer require crystoline/xpsr-audit
```

Add the provider to `app.registry` in `config/app.php`, after the database and ORM providers, and the middleware to `app.middleware`, so entries recorded during a request carry its client IP and user agent:

```php
'registry' => [
    // ...
    Crystoline\Xpsr\Audit\AuditServiceRegistry::class,
],
'middleware' => [
    // ...
    Crystoline\Xpsr\Audit\AuditContextMiddleware::class,
],
```

Create and run the migration for the table:

```sh
php xpsr make:audit-table
php xpsr migrate
```

`config/audit.php` is optional:

```php
return [
    'table' => 'audit_log',
    'connection' => null,                                                  // a database connection, or the default one
    'except' => ['created_at', 'updated_at', 'deleted_at'],                // fields never recorded as changes
    'redact' => ['password', 'password_hash', 'remember_token', 'secret', 'token'],  // recorded as "[redacted]"
];
```

## What an entry holds

| | |
|---|---|
| `action` | `created`, `updated`, `deleted`, or the application's own name, such as `payment.refunded` |
| `subject` | What it is about: a type and an ID, such as `App\Model\Payment 42` |
| `actor` | Who did it: the signed-in user's class and ID, or nothing in a command or a job |
| `source`, `ip`, `userAgent`, `requestId` | `http` with the client's details while the middleware handles a request, else `console`; the request ID when `crystoline/xpsr-log-monolog` is installed |
| `changes` | `field => [before, after]` |
| `context` | Anything else given |
| `occurredAt` | UTC |

## Recording by hand

```php
use Crystoline\Xpsr\Audit\AuditLog;

public function __construct(private readonly AuditLog $audit) {}

$this->audit->record('payment.refunded', $payment, changes: ['status' => ['paid', 'refunded']], context: ['reason' => $reason, 'amount' => $amount]);
```

The actor, source, IP, user agent, and request ID are filled in from the current request and identity, so the call site says only what happened. A change given as a bare value, `['note' => 'done']`, is recorded as `[null, 'done']`; a field in `audit.redact` keeps only that it changed. Values are stored as JSON: dates as ISO 8601 text, enums as their value, and objects as their class and ID.

**Subjects** are identified without any setup: an Eloquent model by its morph class and key, a Doctrine entity by its class and identifier, a user by its class and `authIdentifier()`, and any other object by its public `$id` or `getId()`. An object with none of those implements `Crystoline\Xpsr\Audit\AuditableInterface`, or an `AuditKey($type, $id)` is passed instead.

## Recording automatically

**Eloquent** models use the `Audited` trait. Creation records every attribute, an update the attributes that changed with their previous values, and deletion the attributes that were there:

```php
use Crystoline\Xpsr\Audit\Eloquent\Audited;

final class Payment extends Model
{
    use Audited;

    public function auditExcept(): array { return ['notes']; }
    public function auditRedact(): array { return ['card_number']; }
}
```

**Doctrine** entities take the `#[Audited]` attribute; the entity manager's events record inserts, updates with the unit of work's change set, and removals, inside the flush's transaction, so an entry never outlives a rollback:

```php
use Crystoline\Xpsr\Audit\Attribute\Audited;

#[ORM\Entity]
#[Audited(except: ['notes'], redact: ['iban'])]
class Invoice { ... }
```

`except` and `redact` on a model or entity add to `audit.except` and `audit.redact`. An update that touches only excluded fields records nothing.

## Reading the log

```php
$audit->for($payment);                       // entries about a subject, newest first
$audit->by($user);                           // entries an actor made
$audit->recent(100);
$audit->search(action: 'payment.refunded', subject: $payment);
$audit->find($id);
$entry->before('status'); $entry->after('status');
```

`query()` is a DBAL query builder over the table, newest first, to narrow or page; with `crystoline/xpsr-api`, `Pagination::fromRequest($request)->paginate($audit->query())` pages it, and `AuditLog::entry($row)` makes an `Entry` of a row.

`php xpsr audit:prune --days=365` removes older entries; schedule it according to what you must keep.

## Testing

`Crystoline\Xpsr\Audit\Testing\AuditAssertions` adds assertions to an application's tests:

```php
use Crystoline\Xpsr\Audit\Testing\AuditAssertions;

final class RefundTest extends TestCase
{
    use AuditAssertions;

    public function testARefundIsAudited(): void
    {
        $this->actingAs($admin)->postJson("/payments/{$payment->id}/refund")->assertOk();
        $this->assertAudited('payment.refunded', $payment, fn(Entry $entry): bool => $entry->actor?->id === (string) $admin->id);
        $this->assertNotAudited('deleted', $payment);
    }
}
```

## Deployment

The log grows with every change; schedule `audit:prune` and keep the table on a connection whose backups match the retention you need. Entries are written in the same transaction as the change they describe, with the application's database connection, so they cost one insert each. There is no hash chain yet: the log shows what the application recorded, not that nobody altered the table afterwards.
