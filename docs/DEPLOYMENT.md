# Deploying an XPSR application

XPSR runs on any PHP 8.4+ setup that sends requests to a single front controller: Apache with mod_php or PHP-FPM, or nginx with PHP-FPM. The bundled Docker setup is for development only.

## Checklist

1. Install production dependencies: `composer install --no-dev --optimize-autoloader`.
2. Point the web server's document root at `public/`.
3. Set `APP_ENV=production` and leave `APP_DEBUG` unset or `false`.
4. Set PHP's `display_errors=Off` and `log_errors=On`.
5. Set `TRUSTED_PROXIES` if a load balancer or reverse proxy terminates TLS.
6. Set `APP_URL` to the public URL, and make the web server answer only your hostnames.
7. Enable OPcache.
8. After deploying, check `php xpsr route:list` and request a known URL.

## Dependencies

```sh
composer install --no-dev --optimize-autoloader
```

Commit `composer.json` and `composer.lock`, and install from the lock file on servers. Choose the router adapter during development with `php xpsr setup`, which records the package in `composer.json`. Do not run `setup --install-dependencies` on a production server.

With `--no-dev`, only packages in `require` are installed. This leaves out Whoops, which only formats debug error pages; if debug is ever enabled in production, a plain built-in page is used instead. API-only applications can also `composer remove twig/twig`. Rendering a view then fails with the install command, but nothing else depends on Twig. The `setup` command already treats an adapter installed only through `require-dev` as missing for this reason. Classes created later with `make:*` need `composer dump-autoload` only if you use `--classmap-authoritative`.

## Web server

Only `public/` may be exposed. Everything else (`.env`, `config/`, `vendor/`, `composer.json`) lives outside the document root.

**Apache.** Set the `DocumentRoot` to `public/`, enable `mod_rewrite`, and allow the included `public/.htaccess` (`AllowOverride All`, or copy its rules into the virtual host). The rules send every request that is not an existing file to `index.php` and pass the `Authorization` header through.

**nginx with PHP-FPM** (a starting point; the test suite covers Apache and PHP's built-in server, not nginx):

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/my-app/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known) { deny all; }
    location ~ \.php$ { return 404; }
}
```

Add a `default_server` block that rejects unknown hostnames (`return 444;`). The request URI's host comes from the client's `Host` header, so build absolute links from `APP_URL`, as the `url()` helper does.

## Environment and configuration

`.env` is loaded if present, but real environment variables take precedence over it. In production, prefer environment variables from the process manager or orchestrator, and keep `.env` out of version control (the skeleton's `.gitignore` already does).

| Variable | Production value | Effect |
|---|---|---|
| `APP_ENV` | `production` | Loads overrides from `config/production/*.php`, if that directory exists |
| `APP_DEBUG` | unset or `false` | Error responses show only the reason phrase, such as "Internal Server Error", never exception details |
| `APP_URL` | `https://example.com` | Base for absolute URLs |
| `ROUTER_ADAPTER` | `fastroute` or `sunrise` | Must match an installed adapter package |
| `LOG_LEVEL` | for example `warning` | Minimum PSR-3 level written to the log |
| `TRUSTED_PROXIES` | for example `10.0.0.0/8` | See below |

Per-environment settings belong in `config/{APP_ENV}/` files. Each file overrides only the keys it returns.

## Errors and logging

Exceptions become JSON or HTML error responses (see [HTTP.md](HTTP.md)). Server errors (5xx) are logged with their stack trace through PHP's `error_log()`: into the web server's or PHP-FPM's error log, or the file named by the `error_log` ini setting. To use a different logger, bind `Psr\Log\LoggerInterface` in `config/factory.php`, for example to Monolog.

Keep `display_errors=Off` in production. PHP warnings and deprecation notices printed before the response would corrupt it. The response emitter refuses to send such a response and logs `Cannot send the response: output already started`. With output buffering on (the production default), the client then gets a clean 500 instead. XPSR's minimum dependency versions are chosen so that none of them emit deprecation notices on PHP 8.4 or 8.5, and CI checks this.

If the application cannot boot at all, `public/index.php` logs the cause and returns a plain "Internal Server Error".

## Proxies and HTTPS

When a load balancer terminates TLS, the application sees plain HTTP, and the scheme comes from `X-Forwarded-Proto`. XPSR honors `Forwarded` and `X-Forwarded-*` headers only from addresses listed in `TRUSTED_PROXIES` (comma-separated IP addresses or CIDR ranges, IPv4 or IPv6). From any other client, those headers are removed before the application sees the request, so nobody can fake HTTPS or a client address by sending them.

```sh
TRUSTED_PROXIES=10.0.0.0/8,192.168.0.10
```

## Writable directories

The default application writes nothing to disk at runtime. If you enable Twig's template cache, set `view_cache_dir` (for example in `config/production/app.php`) to a directory the web server user can write, such as `var/cache/views`, and clear it on each deploy.

## Performance

- Enable OPcache. With `opcache.validate_timestamps=0`, reload PHP-FPM or Apache on every deploy.
- `--optimize-autoloader` builds a class map for faster autoloading.
- Route and configuration caches are not available yet; route files and configuration load on each request.

## Not provided yet

- **Sessions and CSRF.** The skeleton does not start sessions. The included `SessionServiceRegistry` is an unregistered example, not a hardened session integration. For browser forms, add a session and CSRF solution yourself.
- **Process supervision for workers or queues.** XPSR has no queue or worker runtime.
- **Long-running servers** (RoadRunner, FrankenPHP worker mode, Swoole). Isolation between requests in a single long-running process has not been verified.

## After deploying

```sh
php xpsr about            # environment, debug flag, router
php xpsr route:list       # loads every route file; fails if one is missing or invalid
curl -fsS https://example.com/your-health-route
```
