# crystoline/xpsr-auth-oauth2-server

An OAuth2 authorization server for XPSR applications, on [league/oauth2-server](https://oauth2.thephpleague.com/): other applications get tokens to call your API, as themselves (client credentials) or for one of your users who approved them (authorization code with PKCE, and refresh tokens). Their tokens are recognized by the `oauth2` guard driver, so `Authenticate`, `RequireScopes`, and `Identity` work as they do for every other token.

```sh
composer require crystoline/xpsr-auth-oauth2-server
php xpsr make:oauth-tables && php xpsr migrate
php xpsr oauth:keys --write
```

Add the provider to `app.registry` in `config/app.php`, after the database, auth, session, and view providers:

```php
Crystoline\Xpsr\Database\DatabaseServiceRegistry::class,
Crystoline\Xpsr\Registry\AuthServiceRegistry::class,
Crystoline\Xpsr\Auth\OAuth2\OAuth2ServiceRegistry::class,
```

Describe the scopes an application may ask for in `config/oauth.php`, and give a guard the `oauth2` driver in `config/auth.php`:

```php
// config/oauth.php
return [
    'scopes' => [
        'orders:read' => 'Read your orders',
        'orders:write' => 'Create and change orders',
        'profile' => 'See your name and email address',
    ],
    'encryption_key' => env('OAUTH_ENCRYPTION_KEY'),
];

// config/auth.php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'api' => ['driver' => 'oauth2', 'provider' => 'users'],
],
```

Then route the two endpoints. The token endpoint is for applications, outside the session and CSRF middleware; the authorization endpoint is a page for the user's browser, behind the session guard:

```php
// routes/api.php
$routes->post('/oauth/token', [TokenController::class, 'token']);

// routes/web.php, inside the group with SessionMiddleware and CsrfMiddleware
$routes->get('/oauth/authorize', [AuthorizeController::class, 'show'])->middleware(Authenticate::class);
$routes->post('/oauth/authorize', [AuthorizeController::class, 'decide'])->middleware(Authenticate::class);
```

## Clients

An application that wants tokens is registered as a client. A confidential client runs on a server and keeps a secret; a public client (a mobile or browser application) cannot keep one, gets none, and proves itself with PKCE instead.

```sh
php xpsr oauth:client "Reporting service" --scope=orders:read
php xpsr oauth:client "Mobile app" --redirect=https://app.example/callback --public
php xpsr oauth:client "Partner" --redirect=https://partner.example/cb --grant=authorization_code --grant=refresh_token --grant=client_credentials
php xpsr oauth:clients
php xpsr oauth:client-revoke 3f2a0c...
```

The command prints the client ID, and the secret once. From code:

```php
use Crystoline\Xpsr\Auth\OAuth2\Clients;

$new = $clients->create('Mobile app', ['https://app.example/callback'], confidential: false);
$new->client->id;      // give to the application
$new->secret;          // null here; a confidential client's secret, shown once
$clients->find($id);
$clients->all();
$clients->revoke($id); // and every token it holds
```

Without `grants`, a client with redirect URIs gets `authorization_code` and `refresh_token`, and one without gets `client_credentials`. Without `scopes`, a client may ask for every configured scope; with a list, only for those, and a request for another is refused with `invalid_scope`. A redirect URI must be an absolute URL without a fragment: `https://app.example/callback`, `myapp://callback`, or `http://127.0.0.1:8080/callback` (a loopback address may use any port, as RFC 8252 asks). Secrets are stored as SHA-256 hashes, so a copy of the table cannot be used to get tokens.

## Client credentials

A service calls your API as itself:

```http
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=…&client_secret=…&scope=orders:read
```

```json
{"token_type": "Bearer", "expires_in": 3600, "access_token": "eyJ…"}
```

The client may send its ID and secret as HTTP basic authentication instead. Its requests, with `Authorization: Bearer <token>`, authenticate as the client: `Identity::of($request)->user` is the `Client`, with `->id` and `->name`, and `Identity::of($request)->scopes()` lists what it asked for. The application decides what a client may do with policies or `RequireScopes`, as for any user.

## Authorization code with PKCE

An application acting for one of your users sends their browser to the authorization endpoint:

```
GET /oauth/authorize?response_type=code&client_id=…&redirect_uri=https://app.example/callback
    &scope=orders:read%20profile&state=…&code_challenge=…&code_challenge_method=S256
```

A guest is sent to sign in first and comes back. The consent page then shows the application's name and what it asks for, and the user approves or denies. The browser goes back to the application's redirect URI with `code` and `state`, or with `error=access_denied`. The application exchanges the code within ten minutes:

```http
POST /oauth/token

grant_type=authorization_code&client_id=…&code=…&redirect_uri=https://app.example/callback&code_verifier=…
```

A confidential client adds `client_secret`; a public client must have sent a `code_challenge` (S256, or plain) and now sends the `code_verifier`. The answer has an access token, valid for an hour, and a refresh token, valid for thirty days:

```http
POST /oauth/token

grant_type=refresh_token&client_id=…&refresh_token=…
```

Each refresh gives a new pair and ends the old one. The access token authenticates as the user who approved, looked up in the guard's provider, with the scopes they approved; `Identity::of($request)->tokenId` is the token's ID.

### The consent page

The page is `views/oauth/authorize.twig` (or the engine's own extension) when the application has one, otherwise a built-in page. It gets `client` (`id`, `name`), `scopes` (a list of `id` and `description`, from `oauth.scopes`), `user`, `action` (the URL to post to), and `csrf_token`, and posts `action` as `approve` or `deny`:

```twig
<h1>{{ client.name }} wants access to your account</h1>
<ul>{% for scope in scopes %}<li>{{ scope.description }}</li>{% endfor %}</ul>
<form method="post" action="{{ action }}">
    <input type="hidden" name="_token" value="{{ csrf_token }}">
    <button name="action" value="approve">Approve</button>
    <button name="action" value="deny">Deny</button>
</form>
```

`oauth.view` names another view; `null` keeps the built-in page.

## Tokens

```php
use Crystoline\Xpsr\Auth\OAuth2\Tokens;

$tokens->forUser($user);                  // the applications a user connected: client name, scopes, created, expires
$tokens->revoke($identity->tokenId);      // end one token and its refresh tokens
$tokens->revokeForUser($user);            // disconnect every application; or one, with its client ID
$tokens->purge();                         // remove expired and revoked rows
```

Access tokens are signed JWTs (RS256) that carry the client (`aud`), the user (`sub`), the scopes, and the expiry; a request is refused once the token expires or is revoked, and when its client is revoked. Authorization codes and refresh tokens are encrypted with `OAUTH_ENCRYPTION_KEY`. A copy of the public key lets another service verify the tokens on its own, but revocation is known only here.

Schedule `php xpsr oauth:purge` daily to keep the tables small.

## Keys

`php xpsr oauth:keys` writes an RSA key pair to `var/oauth/private.key` and `var/oauth/public.key`, readable only by their owner, and prints the encryption key; `--write` sets `OAUTH_ENCRYPTION_KEY` in `.env`. Keep all three out of version control, and give every server the same. `--force` replaces keys that exist; every issued token, code, and refresh token then stops working.

## Configuration

`config/oauth.php`:

| Option | Default | Meaning |
|---|---|---|
| `scopes` | none | Scope to description, shown on the consent page. A token gets only configured scopes. |
| `grants` | all three | The grants to enable: `client_credentials`, `authorization_code`, `refresh_token`. |
| `access_token_ttl` | `1 hour` | How long an access token is valid. |
| `refresh_token_ttl` | `30 days` | How long a refresh token is valid. |
| `auth_code_ttl` | `10 minutes` | How long an authorization code may be exchanged. |
| `private_key`, `public_key` | `var/oauth/private.key`, `var/oauth/public.key`, or `OAUTH_PRIVATE_KEY` and `OAUTH_PUBLIC_KEY` | The RSA key files, relative to the project root. |
| `encryption_key` | `OAUTH_ENCRYPTION_KEY` | Encrypts authorization codes and refresh tokens; from `oauth:keys`. |
| `tables` | `oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_auth_codes` | The table names (`clients`, `access_tokens`, `refresh_tokens`, `auth_codes`). |
| `connection` | the default | The database connection. |
| `view` | `oauth.authorize` | The consent page's view; the built-in page when it does not exist, or always with `null`. |

Invalid values fail on first use with the setting's name.

## Commands

| Command | Does |
|---|---|
| `make:oauth-tables` | Writes the migration for the four tables. |
| `oauth:keys [--write] [--force]` | Makes the key pair and the encryption key. |
| `oauth:client <name> [--redirect=…] [--grant=…] [--scope=…] [--public]` | Registers a client and prints its secret. |
| `oauth:clients [--json]` | Lists the clients. |
| `oauth:client-revoke <id>` | Revokes a client and its tokens. |
| `oauth:purge` | Removes expired and revoked tokens and codes. |

## Services

| Service | Provides |
|---|---|
| `Crystoline\Xpsr\Auth\OAuth2\Clients` | `create()`, `find()`, `all()`, `revoke()`. |
| `Crystoline\Xpsr\Auth\OAuth2\Tokens` | `forUser()`, `revoke()`, `revokeForUser()`, `revokeForClient()`, `purge()`. |
| `Crystoline\Xpsr\Auth\OAuth2\Scopes` | `all()`, `has()`, `describe()`. |
| `Crystoline\Xpsr\Auth\OAuth2\Http\TokenController` | `token()`, for the token route. |
| `Crystoline\Xpsr\Auth\OAuth2\Http\AuthorizeController` | `show()` and `decide()`, for the authorization routes. |
| `League\OAuth2\Server\AuthorizationServer`, `League\OAuth2\Server\ResourceServer` | The library's servers, for anything beyond this. |
| `auth.driver.oauth2` | The guard factory `AuthManager` uses for `'driver' => 'oauth2'`. |

## Testing

Tests of an API behind the `oauth2` guard use `actingAs($user, 'api', ['orders:read'])` as with any token; to act as a client, pass the `Client` from `Clients::create()`. The package's own tests run the real flows with keys made once per process.

## Not provided

OpenID Connect ID tokens, the device grant, token introspection and a revocation endpoint, dynamic client registration, a JWKS endpoint (copy the public key to a service that verifies tokens itself), and the password and implicit grants, which OAuth 2.1 removed.

Requires PHP 8.4+ with the `openssl` extension, `crystoline/xpsr-database`, and `league/oauth2-server` 8.5.5 or 9.4+.
