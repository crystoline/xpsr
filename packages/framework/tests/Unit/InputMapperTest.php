<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Tests\Unit;

use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\Validation\Attribute\EmptyToNull;
use Crystoline\Xpsr\Validation\Attribute\Field;
use Crystoline\Xpsr\Validation\Attribute\Filter;
use Crystoline\Xpsr\Validation\Attribute\Input;
use Crystoline\Xpsr\Validation\Attribute\Lowercase;
use Crystoline\Xpsr\Validation\Attribute\Replace;
use Crystoline\Xpsr\Validation\Attribute\Trim;
use Crystoline\Xpsr\Validation\Attribute\Uppercase;
use Crystoline\Xpsr\Validation\FilterInterface;
use Crystoline\Xpsr\Validation\InputMapper;
use Crystoline\Xpsr\Validation\ValidationException;
use DateTimeImmutable;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class InputMapperTest extends TestCase
{
    public function testFieldsAreConvertedToTheParameterTypes(): void
    {
        $input = (new InputMapper())->map([
            'title' => 'Ship it',
            'count' => '3',
            'ratio' => '0.25',
            'urgent' => 'on',
            'tags' => ['a', 'b'],
            'dueAt' => '2026-11-15',
            'priority' => 'high',
            'level' => '2',
            'anything' => ['x' => 1],
            'ignored' => 'fields the class does not name are ignored',
        ], MapperTask::class);

        self::assertSame(['Ship it', 3, 0.25, true, ['a', 'b']], [$input->title, $input->count, $input->ratio, $input->urgent, $input->tags]);
        self::assertSame('2026-11-15', $input->dueAt?->format('Y-m-d'));
        self::assertSame([MapperPriority::High, MapperLevel::Two, ['x' => 1]], [$input->priority, $input->level, $input->anything]);
    }

    public function testDefaultsAndNullableFieldsMayBeLeftOut(): void
    {
        $input = (new InputMapper())->map(['title' => 'Ship it', 'count' => 1], MapperTask::class);
        self::assertSame([0.0, false, [], null, MapperPriority::Normal, null], [$input->ratio, $input->urgent, $input->tags, $input->dueAt, $input->priority, $input->level]);
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        try {
            (new InputMapper())->map([
                'title' => 42,
                'count' => '3.5',
                'ratio' => 'many',
                'urgent' => 'maybe',
                'tags' => 'a,b',
                'dueAt' => 'not a date at all',
                'priority' => 'urgent',
                'level' => 'nine',
            ], MapperTask::class);
            self::fail('Invalid input was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame([
                'title' => ['This value should be of type string.'],
                'count' => ['This value should be of type int.'],
                'ratio' => ['This value should be of type float.'],
                'urgent' => ['This value should be of type bool.'],
                'tags' => ['This value should be of type array.'],
                'dueAt' => ['This value is not a valid datetime.'],
                'priority' => ['The value you selected is not a valid choice.'],
                'level' => ['The value you selected is not a valid choice.'],
            ], $exception->errors());
            self::assertSame(422, $exception->getStatusCode());
        }
    }

    public function testMissingAndNullFieldsAreReported(): void
    {
        try {
            (new InputMapper())->map(['count' => null], MapperTask::class);
            self::fail('Missing fields were accepted.');
        } catch (ValidationException $exception) {
            self::assertSame(['title' => ['This field is missing.'], 'count' => ['This value should not be null.']], $exception->errors());
        }
    }

    public function testFieldNamesFollowFieldAttributesAndSnakeNaming(): void
    {
        $input = (new InputMapper())->map(['customer_name' => 'Ada', 'billing_email' => 'ada@example.test', 'po' => 'PO-1', 'dueAt' => '2026-01-01'], MapperInvoice::class);
        self::assertSame(['Ada', 'ada@example.test', 'PO-1'], [$input->customerName, $input->billingEmail, $input->purchaseOrderNumber]);
        self::assertSame('2026-01-01', $input->due->format('Y-m-d'), '#[Field] wins over the naming.');

        try {
            (new InputMapper())->map(['customerName' => 'Ada'], MapperInvoice::class);
            self::fail('Property names were accepted as field names.');
        } catch (ValidationException $exception) {
            self::assertSame(['customer_name', 'billing_email', 'dueAt'], array_keys($exception->errors()), 'Errors use the field names the client sends.');
        }
    }

    public function testFiltersRunBeforeConversionInOrder(): void
    {
        $container = new ServiceContainer();
        $container->instance(MapperDigitsOnly::class, new MapperDigitsOnly());
        $input = (new InputMapper($container))->map([
            'email' => '  Ada@Example.COM ',
            'password' => '  secret with spaces  ',
            'nickname' => '   ',
            'code' => ' ab-c ',
            'phone' => '+1 (555) 010-9999',
            'count' => ' 12 ',
        ], MapperRegistration::class);

        self::assertSame('ada@example.com', $input->email);
        self::assertSame('  secret with spaces  ', $input->password, 'Trim(false) switches the class default off.');
        self::assertNull($input->nickname, 'Trimmed to "" and then EmptyToNull.');
        self::assertSame('ABC', $input->code, 'Defaults first, then the field\'s own filters.');
        self::assertSame('15550109999', $input->phone, 'A container-built custom filter.');
        self::assertSame(12, $input->count, 'Trimmed before conversion.');
    }

    public function testTextEmptiedByFiltersIsABlankField(): void
    {
        try {
            (new InputMapper())->map(['email' => 'a@b.test', 'password' => 'x', 'nickname' => null, 'code' => '   ', 'phone' => '1', 'count' => 1], MapperRegistration::class);
            self::fail('A blank required field was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame(['code' => ['This value should not be blank.']], $exception->errors());
        }
        try {
            (new InputMapper())->map(['title' => null, 'count' => 1], MapperTask::class);
            self::fail('An explicit null was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame(['title' => ['This value should not be null.']], $exception->errors(), 'A null that was sent keeps its own message.');
        }
    }

    public function testNestedInputClassesUseDottedErrorPaths(): void
    {
        $order = (new InputMapper())->map(['customer' => 'Ada', 'shipping_address' => ['street' => 'Main St 1', 'post_code' => '10115']], MapperOrder::class);
        self::assertSame('10115', $order->shippingAddress->postCode);

        try {
            (new InputMapper())->map(['customer' => 'Ada', 'shipping_address' => ['street' => 7]], MapperOrder::class);
            self::fail('An invalid nested object was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame([
                'shipping_address.street' => ['This value should be of type string.'],
                'shipping_address.post_code' => ['This field is missing.'],
            ], $exception->errors());
        }

        try {
            (new InputMapper())->map(['customer' => 'Ada', 'shipping_address' => 'Main St 1'], MapperOrder::class);
            self::fail('A string was accepted as an object.');
        } catch (ValidationException $exception) {
            self::assertSame(['shipping_address' => ['This value should be of type object.']], $exception->errors());
        }
    }

    public function testValidatorPropertyPathsBecomeFieldPaths(): void
    {
        $mapper = new InputMapper();
        self::assertSame('shipping_address.post_code', $mapper->fieldPath(MapperOrder::class, 'shippingAddress.postCode'));
        self::assertSame('customer', $mapper->fieldPath(MapperOrder::class, 'customer'));
        self::assertSame('lines[0].sku', $mapper->fieldPath(MapperOrder::class, 'lines[0].sku'), 'Paths into arrays keep their names.');
        self::assertSame('due_on', $mapper->fieldPath(MapperOrder::class, 'dueOn'));
    }

    public function testUnionTypesAcceptAnyMember(): void
    {
        $mapper = new InputMapper();
        self::assertSame(5, $mapper->map(['value' => 5], MapperUnion::class)->value);
        self::assertSame('five', $mapper->map(['value' => 'five'], MapperUnion::class)->value);
        $this->expectException(ValidationException::class);
        $mapper->map(['value' => ['five']], MapperUnion::class);
    }

    public function testRequestSourcesAreRead(): void
    {
        $request = (new ServerRequest('POST', '/tasks?page=2'))
            ->withParsedBody(['title' => 'From the body'])
            ->withQueryParams(['page' => '2'])
            ->withAttribute('_route_params', ['id' => '7']);
        self::assertSame(['title' => 'From the body'], InputMapper::data($request, 'body'));
        self::assertSame(['page' => '2'], InputMapper::data($request, 'query'));
        self::assertSame(['id' => '7'], InputMapper::data($request, 'route'));
        self::assertSame([], InputMapper::data(new ServerRequest('POST', '/'), 'body'), 'No body is no fields.');

        try {
            InputMapper::data($request->withParsedBody(['a', 'b']), 'body');
            self::fail('A JSON list was accepted as an object.');
        } catch (ValidationException $exception) {
            self::assertSame(['body' => ['This value should be of type object.']], $exception->errors());
        }
    }

    public function testMistakesInInputClassesAreReportedToTheDeveloper(): void
    {
        foreach ([
            MapperDuplicateFields::class => 'both read the field "email"',
            MapperWithoutConstructor::class => 'needs a public constructor',
            MapperUnsupportedType::class => 'cannot be read from request data',
        ] as $class => $message) {
            try {
                (new InputMapper())->map(['email' => 'a', 'value' => 'x'], $class);
                self::fail("{$class} was accepted.");
            } catch (LogicException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}

enum MapperPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}

enum MapperLevel: int
{
    case One = 1;
    case Two = 2;
}

final class MapperTask
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $title,
        public readonly int $count,
        public readonly float $ratio = 0.0,
        public readonly bool $urgent = false,
        public readonly array $tags = [],
        public readonly ?DateTimeImmutable $dueAt = null,
        public readonly MapperPriority $priority = MapperPriority::Normal,
        public readonly ?MapperLevel $level = null,
        public readonly mixed $anything = null,
    ) {}
}

#[Input(naming: 'snake')]
final class MapperInvoice
{
    public function __construct(
        public readonly string $customerName,
        public readonly string $billingEmail,
        #[Field('dueAt')]
        public readonly DateTimeImmutable $due,
        #[Field('po')]
        public readonly ?string $purchaseOrderNumber = null,
    ) {}
}

final class MapperDigitsOnly implements FilterInterface
{
    public function filter(mixed $value): mixed
    {
        return is_string($value) ? (string) preg_replace('/\D/', '', $value) : $value;
    }
}

#[Input(filters: [Trim::class, EmptyToNull::class])]
final class MapperRegistration
{
    public function __construct(
        #[Lowercase]
        public readonly string $email,
        #[Trim(false)]
        public readonly string $password,
        public readonly ?string $nickname,
        #[Replace('/-/'), Uppercase]
        public readonly string $code,
        #[Filter(MapperDigitsOnly::class)]
        public readonly string $phone,
        public readonly int $count,
    ) {}
}

#[Input(naming: 'snake')]
final class MapperAddress
{
    public function __construct(public readonly string $street, public readonly string $postCode) {}
}

#[Input(naming: 'snake')]
final class MapperOrder
{
    /** @param list<array<string, mixed>> $lines */
    public function __construct(
        public readonly string $customer,
        public readonly MapperAddress $shippingAddress,
        public readonly array $lines = [],
        public readonly ?DateTimeImmutable $dueOn = null,
    ) {}
}

final class MapperUnion
{
    public function __construct(public readonly int|string $value) {}
}

final class MapperDuplicateFields
{
    public function __construct(public readonly string $email, #[Field('email')] public readonly string $other = '') {}
}

final class MapperWithoutConstructor {}

final class MapperUnsupportedType
{
    public function __construct(public readonly \Closure $value) {}
}
