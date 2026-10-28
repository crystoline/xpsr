<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Symfony\Tests;

use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Exception\ExceptionHandler;
use Crystoline\Xpsr\Foundation\ServiceProviderManager;
use Crystoline\Xpsr\ServiceContainer\ServiceContainer;
use Crystoline\Xpsr\Validation\Attribute\Field;
use Crystoline\Xpsr\Validation\Attribute\Input;
use Crystoline\Xpsr\Validation\Attribute\Lowercase;
use Crystoline\Xpsr\Validation\Attribute\Trim;
use Crystoline\Xpsr\Validation\ValidationException;
use Crystoline\Xpsr\Validation\ValidatorInterface;
use Crystoline\Xpsr\Validation\Symfony\SymfonyValidator;
use Crystoline\Xpsr\Validation\Symfony\ValidationServiceRegistry;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\Constraints as Assert;

final class SymfonyValidatorTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $container = new ServiceContainer();
        $container->instance(Configuration::class, new Configuration(['app' => ['debug' => true]]));
        $container->instance(CommandRegistry::class, new CommandRegistry());
        (new ServiceProviderManager($container))->boot([ValidationServiceRegistry::class]);
        $this->validator = $container->get(ValidatorInterface::class);
        self::assertInstanceOf(SymfonyValidator::class, $this->validator);
        self::assertArrayHasKey('make:input', $container->get(CommandRegistry::class)->all());
    }

    public function testValidInputBecomesATypedObject(): void
    {
        $input = $this->validator->validate($this->body(['title' => '  Ship it ', 'priority' => 'high', 'due_at' => '+1 week']), ValidatedTask::class);
        self::assertSame(['Ship it', 'high'], [$input->title, $input->priority]);
        self::assertGreaterThan(new DateTimeImmutable(), $input->dueAt);
    }

    public function testRuleViolationsUseTheClientsFieldNames(): void
    {
        $errors = $this->errors(fn() => $this->validator->validate($this->body(['title' => ' ', 'priority' => 'urgent', 'due_at' => 'yesterday']), ValidatedTask::class));
        self::assertSame(['title', 'priority', 'due_at'], array_keys($errors));
        self::assertSame(['This value should not be blank.'], $errors['title']);
        self::assertSame(['The value you selected is not a valid choice.'], $errors['priority']);
        // Symfony formats the date with ext-intl when it is loaded ("Oct 28, 2026, 12:00 AM"), otherwise as Y-m-d H:i:s.
        self::assertCount(1, $errors['due_at']);
        self::assertStringStartsWith('This value should be greater than ', $errors['due_at'][0]);
    }

    public function testTypeErrorsAreReportedAsFieldErrors(): void
    {
        self::assertSame(['title' => ['This value should be of type string.']], $this->errors(fn() => $this->validator->validate($this->body(['title' => 42]), ValidatedTask::class)));
    }

    public function testQueryStringsAndRouteParametersAreSources(): void
    {
        $request = (new ServerRequest('GET', '/tasks'))->withQueryParams(['page' => '2', 'per_page' => '50'])->withAttribute('_route_params', ['id' => '7']);
        $filters = $this->validator->validate($request, ValidatedListing::class, 'query');
        self::assertSame([2, 50], [$filters->page, $filters->perPage]);
        self::assertSame(7, $this->validator->validate($request, ValidatedId::class, 'route')->id);

        $errors = $this->errors(fn() => $this->validator->validate($request->withQueryParams(['page' => '0', 'per_page' => '500']), ValidatedListing::class, 'query'));
        self::assertSame(['page', 'per_page'], array_keys($errors));
    }

    public function testRulesCanCompareFieldsAndCheckNestedObjects(): void
    {
        $errors = $this->errors(fn() => $this->validator->validate($this->body([
            'current' => 'same-password-1',
            'new' => 'same-password-1',
            'contact' => ['email' => 'not an email'],
        ]), ValidatedPasswordChange::class));
        self::assertSame([
            'new' => ['Choose a different password.'],
            'contact.email' => ['This value is not a valid email address.'],
        ], $errors);
    }

    public function testArraysCanBeValidatedWithoutAClass(): void
    {
        self::assertSame(['email' => 'ada@example.test'], $this->validator->validateArray(['email' => 'ada@example.test', 'other' => 1], [
            'email' => [new Assert\NotBlank(), new Assert\Email()],
            'age' => new Assert\Range(min: 18),
        ]));
        $errors = $this->errors(fn() => $this->validator->validateArray(['age' => 12, 'tags' => ['ok', '']], [
            'email' => [new Assert\NotBlank()],
            'age' => new Assert\Range(min: 18),
            'tags' => new Assert\All([new Assert\NotBlank()]),
        ]));
        self::assertSame([
            'email' => ['This value should not be blank.'],
            'age' => ['This value should be 18 or more.'],
            'tags[1]' => ['This value should not be blank.'],
        ], $errors);
    }

    public function testTheExceptionRendersAsA422WithFieldErrorsInEveryEnvironment(): void
    {
        $factory = new Psr17Factory();
        $exception = new ValidationException(['title' => ['This value should not be blank.']]);
        foreach ([false, true] as $debug) {
            $handler = new ExceptionHandler($factory, $factory, new NullLogger(), $debug, whoops: false);
            $json = $handler->render($factory->createServerRequest('POST', '/')->withHeader('Accept', 'application/json'), $exception);
            self::assertSame(422, $json->getStatusCode());
            self::assertSame(['message' => 'The given data is invalid.', 'errors' => ['title' => ['This value should not be blank.']]], json_decode((string) $json->getBody(), true));

            $html = (string) $handler->render($factory->createServerRequest('POST', '/'), $exception)->getBody();
            self::assertSame("<h1>The given data is invalid.</h1>\n<ul>\n<li><strong>title</strong>: This value should not be blank.</li>\n</ul>", $html, 'No debug page for a client error.');
        }
    }

    /** @param array<string, mixed> $body */
    private function body(array $body): ServerRequest
    {
        return (new ServerRequest('POST', '/'))->withParsedBody($body);
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        self::fail('The input was accepted.');
    }
}

#[Input(filters: [Trim::class])]
final class ValidatedTask
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public readonly string $title,
        #[Assert\Choice(['low', 'normal', 'high'])]
        public readonly string $priority = 'normal',
        #[Field('due_at')]
        #[Assert\GreaterThan('today')]
        public readonly ?DateTimeImmutable $dueAt = null,
    ) {}
}

#[Input(naming: 'snake')]
final class ValidatedListing
{
    public function __construct(
        #[Assert\Positive]
        public readonly int $page = 1,
        #[Assert\Range(min: 1, max: 100)]
        public readonly int $perPage = 20,
    ) {}
}

final class ValidatedId
{
    public function __construct(#[Assert\Positive] public readonly int $id) {}
}

final class ValidatedContact
{
    public function __construct(#[Lowercase, Assert\Email] public readonly string $email) {}
}

final class ValidatedPasswordChange
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $current,
        #[Assert\Length(min: 12)]
        #[Assert\NotEqualTo(propertyPath: 'current', message: 'Choose a different password.')]
        public readonly string $new,
        #[Assert\Valid]
        public readonly ValidatedContact $contact,
    ) {}
}
