# crystoline/xpsr-validation

Request validation for XPSR applications. Each kind of request gets an input class: its constructor parameters are the fields, attributes from [Symfony Validator](https://symfony.com/doc/current/validation.html) are the rules, and XPSR attributes rename fields and normalize values. Invalid input is answered with 422 and the messages for each field.

```sh
composer require crystoline/xpsr-validation
```

Add the provider to `app.registry` in `config/app.php`:

```php
Crystoline\Xpsr\Validation\Symfony\ValidationServiceRegistry::class,
```

## Input classes

```sh
php xpsr make:input CreateTask
```

```php
namespace App\Http\Input;

use Crystoline\Xpsr\Validation\Attribute\{EmptyToNull, Field, Input, Trim};
use Symfony\Component\Validator\Constraints as Assert;

#[Input(filters: [Trim::class, EmptyToNull::class])]
final class CreateTask
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public readonly string $title,

        #[Assert\Choice(['low', 'normal', 'high'])]
        public readonly string $priority = 'normal',

        #[Field('due_at')]
        #[Assert\GreaterThan('today')]
        public readonly ?\DateTimeImmutable $dueAt = null,
    ) {}
}
```

Validate a request in a controller with `Crystoline\Xpsr\Validation\ValidatorInterface`:

```php
use Crystoline\Xpsr\Validation\ValidatorInterface;

public function __construct(
    private readonly ResponseBuilder $responses,
    private readonly ValidatorInterface $validator,
) {}

public function store(ServerRequestInterface $request): ResponseInterface
{
    $input = $this->validator->validate($request, CreateTask::class);
    // $input->title is a non-empty string of at most 200 characters, and so on.

    return $this->responses->json(['title' => $input->title], 201);
}
```

When anything is wrong, `validate()` throws `Crystoline\Xpsr\Validation\ValidationException`, and the controller code after it does not run. The exception handler answers in every environment:

```json
{
    "message": "The given data is invalid.",
    "errors": {
        "title": ["This value should not be blank."],
        "priority": ["The value you selected is not a valid choice."],
        "due_at": ["This value should be greater than 2026-10-28 00:00:00."]
    }
}
```

Every problem is reported at once, each under the field name the client sent. Dates in messages are written by Symfony: as `Oct 28, 2026, 12:00 AM` when PHP's `intl` extension is loaded, otherwise as `2026-10-28 00:00:00`; compare messages in tests by their start, or by field. Browsers that ask for HTML get the same messages as a list. Validation errors are not logged.

## Fields and types

Each constructor parameter is a field. A field that is missing and has no default is reported as "This field is missing."; a nullable parameter without a default becomes `null`. Fields the class does not name are ignored.

Values are converted to the parameter type before the rules run, and a value that cannot be converted is a field error, never a 500:

| Type | Accepts |
|---|---|
| `string` | Strings only: `{"title": 42}` is "This value should be of type string." |
| `int`, `float` | Numbers, and strings that are whole numbers or numbers (`"42"`, `"2.5"`), as forms and query strings send them. |
| `bool` | `true`/`false`, `1`/`0`, and the strings `"true"`, `"false"`, `"1"`, `"0"`, `"on"`, `"off"`, `"yes"`, `"no"`. |
| `array` | Arrays. Check their items with `#[Assert\All([...])]`. |
| `DateTimeImmutable`, `DateTimeInterface` | Date strings that PHP can read, such as `2026-11-15`, `2026-11-15T09:30:00+01:00`, or `+1 week`. |
| A backed enum | One of its values: `"high"` for `Priority::High`. |
| Another input class | An object, mapped the same way. Add `#[Assert\Valid]` to check its rules too; errors use dotted names such as `"address.post_code"`. |
| No type, `mixed` | The value as sent. |

## Field names

By default the field name is the parameter name. `#[Field('due_at')]` renames one field. `#[Input(naming: 'snake')]` names every field in snake case (`customerName` reads `customer_name`); an explicit `#[Field]` still wins. Two parameters reading the same field is an error in the class.

## Filters

Filters normalize values before conversion and validation, so the rules check what you will store. They run in order: the class defaults from `#[Input(filters: [...])]`, then the field's own.

| Filter | Effect |
|---|---|
| `#[Trim]` | Removes whitespace from both ends; `#[Trim(characters: '/')]` removes other characters. |
| `#[Lowercase]`, `#[Uppercase]` | Changes the case of letters, including non-ASCII ones. |
| `#[EmptyToNull]` | Turns `""`, as forms send for an empty field, into `null`. |
| `#[Replace('/[\s-]/', '')]` | Replaces regular expression matches. |
| `#[Filter(PhoneNumber::class)]` | Runs your own filter: a class implementing `Crystoline\Xpsr\Validation\FilterInterface`, built through the container. |

A field can switch a class default off: `#[Trim(false)]` keeps a password's spaces. A field that a filter empties to `null` but that does not allow `null`, such as a required `string` sent as `"   "`, is reported as "This value should not be blank."

Filters only normalize. They are not a security measure: Twig escapes output, and database parameters are bound, so do not strip tags or quotes from input. The request itself is never changed; `$request->getParsedBody()` still shows what the client sent.

## Sources

```php
$filters = $this->validator->validate($request, ListTasks::class, from: 'query');   // ?page=2
$target = $this->validator->validate($request, TaskId::class, from: 'route');       // /tasks/{id}
```

`from` is `body` (the default: the parsed JSON or form body), `query`, or `route` (the route's parameters). A body that is a JSON list rather than an object is reported under `"body"`.

## Rules

Any [Symfony Validator constraint](https://symfony.com/doc/current/reference/constraints.html) works, including comparisons between fields:

```php
#[Assert\Length(min: 12)]
#[Assert\NotEqualTo(propertyPath: 'current', message: 'Choose a different password.')]
public readonly string $new,
```

Messages are in English.

## Without a class

```php
use Symfony\Component\Validator\Constraints as Assert;

$data = $this->validator->validateArray($request->getParsedBody() ?? [], [
    'email' => [new Assert\NotBlank(), new Assert\Email()],
    'age' => new Assert\Range(min: 18),
]);
```

A missing field is checked as `null`. The result holds the fields named in the rules that were sent, without conversion or filters.

## Testing

```php
$response = $this->postJson('/tasks', ['title' => '']);

self::assertSame(422, $response->getStatusCode());
self::assertSame(['This value should not be blank.'], json_decode((string) $response->getBody(), true)['errors']['title']);
```

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Validation\ValidatorInterface` | `validate()` and `validateArray()`. Shared. |
| `Crystoline\Xpsr\Validation\InputMapper` | Maps request data onto input classes, without rules. |
| `Symfony\Component\Validator\Validator\ValidatorInterface` | Symfony's validator, for other objects. Rule metadata is cached in the application cache when `crystoline/xpsr-cache` is installed and `APP_DEBUG` is off. |

`ValidatorInterface`, `ValidationException`, the attributes, and the mapping belong to the framework, so another implementation can replace this package without changing input classes' field names, filters, or types.

Requires PHP 8.4+.
