# crystoline/xpsr-framework

The XPSR framework library: application lifecycle and configuration, the PSR-11 container and provider lifecycle, the PSR-15 HTTP kernel and middleware, portable route definitions and the router contract, error rendering and PSR-3 logging, the response emitter, the PSR-14 event dispatcher, and the `xpsr` console with its generators and setup command.

It does not include a router implementation. Install an adapter alongside it:

| Adapter | Package | `ROUTER_ADAPTER` |
|---|---|---|
| FastRoute (default) | `crystoline/xpsr-router-fastroute` | `fastroute` |
| Sunrise | `crystoline/xpsr-router-sunrise` | `sunrise` |

Most applications start from the `crystoline/xpsr` skeleton instead of requiring this package directly. Documentation lives in the XPSR repository's `docs/` directory.

## Writing a router adapter

Implement `Crystoline\Xpsr\Contracts\Routing\RouterInterface`, usually by extending `Crystoline\Xpsr\Routing\AbstractRouter`. Then prove it with the shared contract suite: extend `Crystoline\Xpsr\Testing\RouterContractTestCase` in your tests and return your router from `createRouter()`. The suite needs `phpunit/phpunit` and `nyholm/psr7` as development dependencies.

## Tests

```sh
composer install
vendor/bin/phpunit
```

Requires PHP 8.4+.
