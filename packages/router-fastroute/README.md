# crystoline/xpsr-router-fastroute

[FastRoute](https://github.com/nikic/FastRoute) adapter for XPSR's portable route definitions. This is the default adapter in the `crystoline/xpsr` skeleton.

```sh
composer require crystoline/xpsr-router-fastroute
```

Select it with `ROUTER_ADAPTER=fastroute`, or run `php xpsr setup --router=fastroute`. Route files stay the same whichever adapter you choose.

The adapter passes XPSR's shared router contract suite (`Crystoline\Xpsr\Testing\RouterContractTestCase`):

```sh
composer install
vendor/bin/phpunit
```
