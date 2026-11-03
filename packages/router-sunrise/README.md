# crystoline/xpsr-router-sunrise

[Sunrise router](https://github.com/sunrise-php/http-router) adapter for XPSR's portable route definitions. It is mainly a migration path for applications written against the earlier Sunrise-based XPSR.

```sh
php xpsr setup --router=sunrise --install-dependencies
# or: composer require crystoline/xpsr-router-sunrise, then set ROUTER_ADAPTER=sunrise
```

Route files stay the same whichever adapter you choose. The adapter runs on `sunrise/http-router` 3 (3.4 or later), which accepts `psr/http-message` 1.1 or 2.0, so it installs beside any other package. It only matches: XPSR runs the handlers and middleware, so none of Sunrise's own request handling, loaders, or OpenAPI tooling is involved. An application that calls Sunrise 2 classes itself needs the 0.7 adapter until it moves to 3.

The adapter passes XPSR's shared router contract suite (`Crystoline\Xpsr\Testing\RouterContractTestCase`):

```sh
composer install
vendor/bin/phpunit
```
