# crystoline/xpsr-router-sunrise

[Sunrise router](https://github.com/sunrise-php/http-router) adapter for XPSR's portable route definitions. It is mainly a migration path for applications written against the earlier Sunrise-based XPSR.

```sh
php xpsr setup --router=sunrise --install-dependencies
# or: composer require crystoline/xpsr-router-sunrise, then set ROUTER_ADAPTER=sunrise
```

Route files stay the same whichever adapter you choose. Sunrise 2.x requires `psr/http-message` 1.x, so installing this adapter keeps the application on that major version. A package that holds the application on `psr/http-message` 2.0, such as `crystoline/xpsr-auth-oauth2-server` through `league/oauth2-server` 9, has to move at the same time, which `setup --install-dependencies` does not ask of Composer: run `composer require crystoline/xpsr-router-sunrise --no-update`, then `composer update crystoline/xpsr-router-sunrise league/oauth2-server --with-all-dependencies`, then `php xpsr setup --router=sunrise`.

The adapter passes XPSR's shared router contract suite (`Crystoline\Xpsr\Testing\RouterContractTestCase`):

```sh
composer install
vendor/bin/phpunit
```
