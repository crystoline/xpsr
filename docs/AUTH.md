# Authentication and authorization

Authentication finds out who sent a request; authorization decides what they may do. XPSR's framework defines both, and packages add storage:

| Part | Where |
|---|---|
| Sessions for browsers, password hashing, `Authenticate` and `Guest` middleware, policies | the framework (`Crystoline\Xpsr\Auth\`) |
| JWTs for APIs (the default `api` guard) | `crystoline/xpsr-auth-jwt` |
| Revocable API tokens in a table, and users in a table | `crystoline/xpsr-database` |
| Users as Doctrine entities | `crystoline/xpsr-orm-doctrine` |

New projects list `Crystoline\Xpsr\Registry\AuthServiceRegistry` in `app.registry` and have `config/auth.php`. The pages in `routes/web.php` run `Authenticate::optional()`, so they know who is signed in, and nothing else changes until you use it.

## Users

Users come from a provider, configured in `auth.users`:

```php
// A table: crystoline/xpsr-database and DatabaseServiceRegistry.
'users' => ['driver' => 'database', 'table' => 'users', 'login' => 'email', 'password' => 'password'],

// Doctrine entities: crystoline/xpsr-orm-doctrine.
'users' => ['driver' => 'doctrine', 'entity' => App\Entity\User::class, 'login' => 'email'],
```

The database provider returns `Crystoline\Xpsr\Auth\GenericUser` objects: `$user->authIdentifier()`, and `$user->get('name')` for any other column. The password column is never included. A users table needs a primary key, a unique login column, and a password column long enough for a hash (255 characters):

```sh
php xpsr make:migration create_users_table --create=users
```

For Doctrine, the entity implements `Crystoline\Xpsr\Auth\UserInterface` (`authIdentifier()` and `authPasswordHash()`), and also `Crystoline\Xpsr\Orm\Doctrine\Auth\PasswordUpgradableInterface` so that sign-in can replace outdated hashes. Any other source works too: implement `Crystoline\Xpsr\Auth\UserProviderInterface` and name its service as the driver.

Logins are compared exactly. Store email addresses in lowercase and lowercase them at sign-in; the `Lowercase` input filter does.

### Passwords

```php
use Crystoline\Xpsr\Auth\PasswordHasher;

$hash = $hasher->hash($password);   // Argon2id when PHP has it, otherwise bcrypt
```

Store the hash, never the password. Sign-in checks it with `password_verify()`, and when the hash was made with an older algorithm or cost, stores a new one.

## Browsers: sign in with the session

```php
use Crystoline\Xpsr\Auth\AuthManager;

public function store(ServerRequestInterface $request): ResponseInterface
{
    $input = $this->validator->validate($request, SignIn::class);
    if ($this->auth->attempt($request, $input->email, $input->password) === null) {
        Session::of($request)->flash('error', 'Those details do not match.');

        return $this->responses->redirect('/login');
    }

    return $this->responses->redirect($this->auth->intended($request));
}

public function destroy(ServerRequestInterface $request): ResponseInterface
{
    $this->auth->logout($request);

    return $this->responses->redirect('/');
}
```

`attempt()` checks the password and signs the user in under a new session ID. `logout()` ends the session. The session also keeps a fingerprint of the password hash, so changing a password signs the user out of their other browsers.

Routes:

```php
use Crystoline\Xpsr\Auth\Http\Authenticate;
use Crystoline\Xpsr\Auth\Http\Guest;
use Crystoline\Xpsr\Cache\RateLimit\RateLimit;

// Inside the group in routes/web.php:
$routes->get('/login', [LoginController::class, 'create'])->middleware(Guest::class);
$routes->post('/login', [LoginController::class, 'store'])->middleware(Guest::class, RateLimit::named('login'));
$routes->post('/logout', [LoginController::class, 'destroy']);
$routes->get('/account', [AccountController::class, 'show'])->middleware(Authenticate::class);
```

- **`Authenticate`** refuses guests. A browser asking for a page is sent to `auth.login_path`, and `intended()` later returns it there. A script (JSON `Accept`, or `X-Requested-With`) or a form post gets 401 instead.
- **`Guest`** sends signed-in browsers to `auth.home_path`.
- **`RateLimit::named('login')`** (`crystoline/xpsr-cache`) limits password guessing; see the cache package's README.

Every form needs `{{ csrf_field() }}`, the login form included; see [SESSIONS.md](SESSIONS.md).

## APIs: tokens

API clients send `Authorization: Bearer <token>` to routes behind the `api` guard:

```php
// routes/api.php
$routes->group('/api', static function (RouteCollection $routes): void {
    $routes->get('/tasks', [TaskController::class, 'index'])->middleware(RequireScopes::of('tasks:read'));
}, [Authenticate::guard('api')]);
```

A request without a valid token gets 401 with `WWW-Authenticate: Bearer`, or `Bearer error="invalid_token"` when it sent a bad token. A token without a listed scope gets 403 with `error="insufficient_scope"`. Sessions allow every scope.

The `api` guard's driver is `AUTH_API_DRIVER`: `jwt` by default, or `token`.

| | `jwt` | `token` |
|---|---|---|
| Package | `crystoline/xpsr-auth-jwt` | `crystoline/xpsr-database` |
| Stored | nothing; the token is signed | a SHA-256 hash per token, in a table |
| Checking a request | signature and claims, then the user by ID | one lookup by hash, then the user |
| Ends | when it expires (`auth.jwt.ttl`, 1 hour) | when revoked, or at its own expiry |
| Suits | short-lived tokens from a sign-in endpoint; other services can verify them | long-lived personal tokens, deploy scripts, integrations |

### JWT

```sh
composer require crystoline/xpsr-auth-jwt
php xpsr auth:jwt-secret --write   # sets AUTH_JWT_SECRET in .env
```

Add `Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry` to `app.registry`, after `AuthServiceRegistry`. A sign-in endpoint issues tokens:

```php
use Crystoline\Xpsr\Auth\Jwt\JwtIssuer;
use Crystoline\Xpsr\Auth\UnauthenticatedException;

public function store(ServerRequestInterface $request): ResponseInterface
{
    $input = $this->validator->validate($request, SignIn::class);
    $user = $this->auth->verify($input->email, $input->password) ?? throw new UnauthenticatedException();
    $token = $this->jwt->issue($user, ['tasks:read', 'tasks:write']);

    return $this->responses->json(['token' => $token->token, 'expires_at' => $token->expiresAt->format(DATE_ATOM)]);
}
```

A JWT cannot be revoked before it expires, so keep `ttl` short. Changing the secret ends every token. See [the package README](../packages/auth-jwt/README.md) for algorithms and options.

### Database tokens

```sh
php xpsr make:token-table && php xpsr migrate
```

and set `AUTH_API_DRIVER=token`.

```php
use Crystoline\Xpsr\Database\Auth\TokenRepository;

$new = $tokens->create($user, 'deploy script', ['tasks:read'], expiresAt: new DateTimeImmutable('+90 days'));
$new->plainText;                        // show it once: xpsr_ followed by 64 hexadecimal characters
$tokens->forUser($user);                // name, scopes, created, last used, expiry
$tokens->revoke($new->token->id);
$tokens->revokeAll($user);
```

From the console:

```sh
php xpsr auth:token ada@example.com --name=deploy --scope=tasks:read --expires="90 days"
php xpsr auth:token-revoke 12
```

The table stores only a SHA-256 hash of each token, so a copy of it cannot be used to sign in.

## In handlers

```php
use Crystoline\Xpsr\Auth\Identity;

$identity = Identity::of($request);     // 401 for a guest
$identity = Identity::find($request);   // null for a guest
$identity->user;                        // the UserInterface
$identity->guard;                       // 'web' or 'api'
$identity->hasScope('tasks:write');
$identity->tokenId;                     // the JWT's jti or the token's ID; null for sessions
```

## Authorization: policies

A policy is a class with one method per action. Methods take the user first, then the record:

```php
namespace App\Policies;

use App\Entity\Task;
use App\Entity\User;

final class TaskPolicy
{
    /** Runs first: true or false decides, null goes on to the method. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin ? true : null;
    }

    public function view(?User $user, Task $task): bool   // ?User: guests reach this method
    {
        return $task->public || $task->ownerId === $user?->id;
    }

    public function update(User $user, Task $task): bool
    {
        return $task->ownerId === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
```

List it in `config/auth.php`:

```php
'policies' => [App\Entity\Task::class => App\Policies\TaskPolicy::class],
```

and check with `Crystoline\Xpsr\Auth\Access\Gate`:

```php
$user = Identity::of($request)->user;
$this->gate->authorize($user, 'update', $task);           // 403 "You are not allowed to do this." when refused
if ($this->gate->allows($user, 'create', Task::class)) {   // a class name: no record yet
    // ...
}
```

An ability like `view-any` calls `viewAny()`. A policy for a parent class or an interface also covers its subclasses. Abilities that are not about a record are defined in a provider's `boot()`:

```php
$this->container->get(Gate::class)->define('view-reports', static fn(User $user): bool => $user->isAdmin);
```

Anything without a policy method or a defined ability is refused, and so are guests, unless the method's user parameter accepts `null`. Type the user parameter with your user class: the database provider's users are `GenericUser`.

## Views

| Function | Gives |
|---|---|
| `{{ auth_user() }}` | The signed-in user, or null: `{% if auth_user() %}Hello {{ auth_user().get('name') }}{% endif %}`. |
| `{{ can('update', task) }}` | Whether the signed-in user may, by the policies. |

They see the user on routes behind `Authenticate` or `Authenticate::optional()`, which the skeleton's web group has.

## Configuration

`config/auth.php`:

| Option | Default | Meaning |
|---|---|---|
| `default` | `web` | The guard `Authenticate` and `Guest` use when none is named. |
| `guards` | `web`: session; `api`: `AUTH_API_DRIVER` (`jwt`) | Each guard's driver: `session`, `jwt`, `token`, or a service implementing `GuardFactoryInterface`. |
| `users` | `database` on `users` (`email`, `password`) | The user provider; see [Users](#users). |
| `login_path`, `home_path` | `/login`, `/` | Where `Authenticate` sends guests and `Guest` sends users. |
| `policies` | none | Model class to policy class. |
| `jwt` | | Options for the jwt driver: `secret`, `algorithm`, `ttl`, and more in [its README](../packages/auth-jwt/README.md). |
| `tokens` | `table` `api_tokens`, the default connection | Options for the token driver. |

## Testing

```php
public function testTheAccountPageShowsTheUser(): void
{
    $this->actingAs(new GenericUser(7, null, ['name' => 'Ada']));
    self::assertStringContainsString('Ada', (string) $this->get('/account')->getBody());
}

public function testTheApiNeedsTheReadScope(): void
{
    $this->actingAs($user, 'api', ['tasks:write']);
    self::assertSame(403, $this->request('GET', '/api/tasks', ['Accept' => 'application/json'])->getStatusCode());
}
```

`actingAs($user, $guard, $scopes)` authenticates every later request in the test, on every guard or one, without a session or token. To test the real sign-in, post the form with `postForm()`; the test keeps the session cookie. `Crystoline\Xpsr\Auth\InMemoryUserProvider` holds users for tests: register an instance as a service and name it in `auth.users.driver`.

## Not included

Registration, password reset, and email verification are application code for now; password reset emails wait for the mail component. Social login, OAuth servers, two-factor sign-in, and "remember me" cookies are not provided.
