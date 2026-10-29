# crystoline/xpsr-auth-jwt

JSON Web Tokens for XPSR APIs, on [lcobucci/jwt](https://lcobucci-jwt.readthedocs.io/): the `jwt` guard driver, which the skeleton's `api` guard uses by default, an issuer for sign-in endpoints, and a secret generator.

```sh
composer require crystoline/xpsr-auth-jwt
php xpsr auth:jwt-secret --write
```

Add the provider to `app.registry` in `config/app.php`, after `AuthServiceRegistry`:

```php
Crystoline\Xpsr\Registry\AuthServiceRegistry::class,
Crystoline\Xpsr\Auth\Jwt\JwtServiceRegistry::class,
```

## Issuing tokens

```php
use Crystoline\Xpsr\Auth\Jwt\JwtIssuer;
use Crystoline\Xpsr\Auth\UnauthenticatedException;

$user = $auth->verify($email, $password) ?? throw new UnauthenticatedException();
$token = $jwt->issue($user, ['tasks:read', 'tasks:write']);          // scopes; ['*'] by default
$token = $jwt->issue($user, ['tasks:read'], ttl: '15 minutes');      // another lifetime

$token->token;       // send to the client
$token->expiresAt;   // DateTimeImmutable, UTC
$token->id;          // the jti claim
```

Clients send it as `Authorization: Bearer <token>` to routes behind `Authenticate::guard('api')`. The token carries `iss`, `aud`, `sub` (the user's ID), `jti`, `iat`, `nbf`, `exp`, and `scope` (space-separated, as in OAuth).

A request's token counts only with a valid signature from the configured algorithm and key, the configured issuer and audience, and the current time between `nbf` and `exp`, give or take the leeway. Tokens signed with another algorithm, including unsigned `alg: none` tokens, are refused. The user is looked up by ID on every request, so a deleted user's tokens stop working.

A JWT cannot be revoked before it expires. Keep the lifetime short and let clients sign in again, or use the `token` driver of `crystoline/xpsr-database` for revocable tokens. Changing the secret ends every token at once.

## Configuration

`auth.jwt` in `config/auth.php`:

| Option | Default | Meaning |
|---|---|---|
| `algorithm` | `HS256` | `HS256`, `HS384`, or `HS512` with `secret`; `RS256` or `ES256` with key files. |
| `secret` | `AUTH_JWT_SECRET` | For HS*: at least 32 bytes (48 for HS384, 64 for HS512). `base64:...` is decoded. |
| `private_key`, `public_key` | | For RS256 and ES256: PEM files, relative to the project root. Another service that only verifies tokens needs only the public key. |
| `ttl` | `1 hour` | How long a token is valid. |
| `issuer` | `app.url` | The `iss` claim; tokens from another issuer are refused. |
| `audience` | the issuer | The `aud` claim. |
| `leeway` | `30` | Seconds of clock difference tolerated, 0 to 300. |

For RS256:

```sh
openssl genrsa -out var/jwt-private.pem 2048
openssl rsa -in var/jwt-private.pem -pubout -out var/jwt-public.pem
```

```php
'jwt' => ['algorithm' => 'RS256', 'private_key' => 'var/jwt-private.pem', 'public_key' => 'var/jwt-public.pem'],
```

Keep the private key and the secret out of version control.

## Commands

```sh
php xpsr auth:jwt-secret                  # print a new secret (64 random bytes, base64)
php xpsr auth:jwt-secret --write          # set AUTH_JWT_SECRET in .env; --force replaces one
php xpsr auth:jwt ada@example.com --scope=tasks:read --ttl="15 minutes"
```

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Auth\Jwt\JwtIssuer` | `issue($user, $scopes, $ttl)`. |
| `auth.driver.jwt` | The guard factory `AuthManager` uses for `'driver' => 'jwt'`. |

Requires PHP 8.4+ with the `openssl` and `sodium` extensions.
