# crystoline/xpsr-router-sunrise

[Sunrise router](https://github.com/sunrise-php/http-router) adapter for XPSR's portable route definitions. It is mainly a migration path for applications written against the earlier Sunrise-based XPSR.

```sh
php xpsr setup --router=sunrise --install-dependencies
# or: composer require crystoline/xpsr-router-sunrise, then set ROUTER_ADAPTER=sunrise
```

Route files stay the same whichever adapter you choose. Sunrise 2.x requires `psr/http-message` 1.x, so installing this adapter keeps the application on that major version.

The adapter passes XPSR's shared router contract suite (`Crystoline\Testing\RouterContractTestCase`):

```sh
composer install
vendor/bin/phpunit
```
