# XPSR packages

XPSR is developed in one repository and installed as separate Composer packages. Applications get only the router they use, and the framework never depends on the application skeleton.

## Packages and dependencies

```text
crystoline/xpsr (skeleton, repository root)
├── crystoline/xpsr-framework          packages/framework
├── crystoline/xpsr-router-fastroute   packages/router-fastroute ──► framework, nikic/fast-route
├── nyholm/psr7                        PSR-7/17 implementation used by config/factory.php
└── (suggested) crystoline/xpsr-router-sunrise   packages/router-sunrise ──► framework, sunrise/http-router
```

| Package | Namespace | Depends on |
|---|---|---|
| `crystoline/xpsr-framework` | `Crystoline\` | PSR interfaces, Symfony Console/Process/Finder/HttpKernel, Illuminate Config, phpdotenv, Twig, Whoops, Nyholm PSR-7 server, and any PSR-17 implementation (`psr/http-factory-implementation`). Accepts `psr/http-message` 1.1 or 2.0. |
| `crystoline/xpsr-router-fastroute` | `Crystoline\Router\FastRoute\` | framework, `nikic/fast-route` ^1.3 |
| `crystoline/xpsr-router-sunrise` | `Crystoline\Router\Sunrise\` | framework, `sunrise/http-router` ^2.16, `psr/http-message` ^1.1 |
| `crystoline/xpsr` | `App\` | framework, FastRoute adapter, `nyholm/psr7` |

Rules that keep the split clean:

- The framework must not reference `App\`, skeleton paths, or adapter classes. It knows the official adapter names (`fastroute`, `sunrise`) and their class names only as strings in `RouteServiceRegistry::ADAPTERS`. If the selected adapter is missing, it reports which package to install.
- Adapters depend on the framework, never on each other.
- Shared test fixtures live in the framework's tests (`Crystoline\Tests\Fixtures\`). Fixtures that need the skeleton live in the root `tests/`.

Known coupling: the `setup` command's presets name the skeleton's route files (`routes/api.php`, `routes/web.php`), and its routing template matches the skeleton's `config/routing.php`. This belongs in an installer package once `xpsr new` exists.

## Versions

All packages are `0.1.x-dev` until the first release. The root path repository assigns that version through its `versions` option, so it does not depend on Git branch detection. Packages require each other with `^0.1@dev`. The `@dev` flags, and setup's `^0.1@dev` constraint, change to `^0.1` with the first tagged release.

## Working in the monorepo

```sh
composer install              # links packages/* into vendor/crystoline/
composer test                 # framework, adapter, and skeleton suites
composer analyse && composer style
composer test:packaging       # slower; needs network access
```

Edits under `packages/` take effect immediately, because `vendor/crystoline/*` are symbolic links. After changing a package's `composer.json`, run `composer update 'crystoline/*'`.

Where tests go:

| Test | Location |
|---|---|
| Framework unit tests | `packages/framework/tests` (`Crystoline\Tests\`) |
| Adapter tests (the shared router contract) | `packages/router-*/tests` |
| Tests that need the skeleton's files: application boot, console, generators, setup, front controller | `tests/` (`Tests\`) |
| Installing packages separately and `composer create-project` | `tests/Packaging` (group `packaging`, excluded from `composer test`) |

Each package has its own `phpunit.xml.dist` and runs its tests standalone after `composer install` in its directory. The packaging test does exactly that.

## Exporting the skeleton

```sh
php scripts/export-skeleton.php /path/to/empty/dir
```

The script copies the files a new application needs and writes a `composer.json` without the monorepo-only parts:

- the `packages/*` path repository;
- autoloading for package tests;
- the analysis and style tooling and their scripts.

It does not copy `composer.lock`. A released skeleton's lock must be built against the published packages.

The packaging test builds a project from this export with `composer create-project`, using the packages through path repositories without symbolic links. It then checks that only the FastRoute adapter is installed, `setup` works, a real `composer require` of the Sunrise adapter succeeds (including moving `psr/http-message` from 2.x to 1.x), requests are served, and generators work.

## Publishing (not done yet)

Publishing needs, in order:

1. **Repositories.** A read-only Git repository per package, kept in sync from this monorepo with a subtree-split job (for example, `splitsh-lite` in CI), and the skeleton from `scripts/export-skeleton.php`.
2. **Packagist.** Registration of `crystoline/xpsr`, `crystoline/xpsr-framework`, `crystoline/xpsr-router-fastroute`, and `crystoline/xpsr-router-sunrise`. Check name availability first.
3. **Tags.** Tag `v0.1.0` in each split repository, then drop the `@dev` flags.
4. **Skeleton lock.** Generate the skeleton's `composer.lock` against the published versions.
5. **CI.** Add a job that installs the packages at their lowest allowed dependency versions (`composer update --prefer-lowest`).
