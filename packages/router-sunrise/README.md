# crystoline/xpsr-router-sunrise

[Sunrise router](https://github.com/sunrise-php/http-router) adapter for XPSR's portable route definitions, for applications that prefer Sunrise's matcher to FastRoute's.

```sh
php xpsr setup --router=sunrise --install-dependencies
# or: composer require crystoline/xpsr-router-sunrise, then set ROUTER_ADAPTER=sunrise
```

Route files stay the same whichever adapter you choose. The adapter runs on `sunrise/http-router` 3 (3.4 or later), which accepts `psr/http-message` 1.1 or 2.0, so it installs beside any other package. It only matches: XPSR runs the handlers and middleware, so none of Sunrise's own request handling, loaders, or OpenAPI tooling is involved. An application that calls Sunrise 2 classes itself needs the 0.7 adapter until it moves to 3.

Requires PHP 8.4+ and `sunrise/http-router` 3.4 or later.
