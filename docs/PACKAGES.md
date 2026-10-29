# XPSR packages

XPSR is developed in one repository and installed as separate Composer packages. Applications get only the router they use, and the framework never depends on the application skeleton.

## Packages and dependencies

```text
crystoline/xpsr (skeleton, repository root)
├── crystoline/xpsr-framework          packages/framework
├── crystoline/xpsr-router-fastroute   packages/router-fastroute ──► framework, nikic/fast-route
├── nyholm/psr7                        PSR-7/17 implementation used by config/factory.php
├── twig/twig                          views (the web example); API-only apps can remove it
├── (dev) filp/whoops                  detailed debug error pages
├── (suggested) crystoline/xpsr-router-sunrise   packages/router-sunrise ──► framework, sunrise/http-router
├── (suggested) crystoline/xpsr-cache            packages/cache ──► framework, symfony/cache
├── (suggested) crystoline/xpsr-http-client     packages/http-client ──► framework, symfony/http-client
├── (suggested) crystoline/xpsr-database        packages/database ──► framework, doctrine/dbal
├── (suggested) crystoline/xpsr-orm-doctrine    packages/orm-doctrine ──► framework, database, doctrine/orm
├── (not published yet) crystoline/xpsr-orm-eloquent   packages/orm-eloquent ──► framework, database, illuminate/database
├── (not published yet) crystoline/xpsr-queue          packages/queue ──► framework, database, symfony/messenger
├── (not published yet) crystoline/xpsr-mail           packages/mail ──► framework, symfony/mailer
├── (suggested) crystoline/xpsr-log-monolog     packages/log-monolog ──► framework, monolog/monolog
├── (suggested) crystoline/xpsr-validation      packages/validation ──► framework, symfony/validator
└── (not published yet) crystoline/xpsr-auth-jwt   packages/auth-jwt ──► framework, lcobucci/jwt
```

| Package | Namespace | Depends on |
|---|---|---|
| `crystoline/xpsr-framework` | `Crystoline\Xpsr\` | PSR interfaces, Symfony Console/Process/Finder, Illuminate Config, phpdotenv, Nyholm PSR-7 server, and any PSR-17 implementation (`psr/http-factory-implementation`). Accepts `psr/http-message` 1.1 or 2.0. Suggests Twig (views) and Whoops (debug pages), but does not require them. |
| `crystoline/xpsr-router-fastroute` | `Crystoline\Xpsr\Router\FastRoute\` | framework, `nikic/fast-route` ^1.3 |
| `crystoline/xpsr-cache` | `Crystoline\Xpsr\Cache\` | framework, `symfony/cache` ^7.4, `symfony/rate-limiter` ^7.4, `psr/cache` 2 or 3, `psr/simple-cache` 1 to 3 (1.x keeps it installable next to the Sunrise adapter) |
| `crystoline/xpsr-database` | `Crystoline\Xpsr\Database\` | framework, `doctrine/dbal` ^4.5. |
| `crystoline/xpsr-orm-doctrine` | `Crystoline\Xpsr\Orm\Doctrine\` | framework, database, `doctrine/orm` ^3.7, `doctrine/persistence` ^4.1, `symfony/cache` ^7.4. |
| `crystoline/xpsr-orm-eloquent` | `Crystoline\Xpsr\Orm\Eloquent\` | framework, database, `illuminate/database` ^12.0, `illuminate/events` ^12.0. Not published yet. |
| `crystoline/xpsr-queue` | `Crystoline\Xpsr\Queue\` | framework, database, `symfony/messenger`, `symfony/doctrine-messenger`, and `symfony/event-dispatcher` 7.4 or 8; Redis, AMQP, and SQS through their Messenger bridges. Not published yet. |
| `crystoline/xpsr-mail` | `Crystoline\Xpsr\Mail\` | framework, `symfony/mailer` and `symfony/mime` 7.4 or 8; `crystoline/xpsr-queue` for queued email; providers through Symfony's mailer bridges. Not published yet. |
| `crystoline/xpsr-log-monolog` | `Crystoline\Xpsr\Log\Monolog\` | framework, `monolog/monolog` ^3.9. |
| `crystoline/xpsr-validation` | `Crystoline\Xpsr\Validation\Symfony\` | framework, `symfony/validator` ^7.4, `symfony/property-access` ^7.4. The contract, attributes, and mapping it uses are in the framework (`Crystoline\Xpsr\Validation\`). |
| `crystoline/xpsr-auth-jwt` | `Crystoline\Xpsr\Auth\Jwt\` | framework, `lcobucci/jwt` ^5.6 (`ext-openssl`, `ext-sodium`). The contracts, session sign-in, and policies are in the framework (`Crystoline\Xpsr\Auth\`); the token driver and database users are in `crystoline/xpsr-database`. Not published yet; see [Adding a package](#adding-a-package). |
| `crystoline/xpsr-http-client` | `Crystoline\Xpsr\HttpClient\` | framework, `symfony/http-client` ^7.4, `psr/http-client` ^1.0.3. |
| `crystoline/xpsr-router-sunrise` | `Crystoline\Xpsr\Router\Sunrise\` | framework, `sunrise/http-router` ^2.16, `psr/http-message` ^1.1 |
| `crystoline/xpsr` | `App\` | framework, FastRoute adapter, `nyholm/psr7`, `twig/twig`; development: `filp/whoops`, `phpunit/phpunit` |

Rules that keep the split clean:

- The framework must not reference `App\`, skeleton paths, or adapter classes. It knows the official adapter names (`fastroute`, `sunrise`) and their class names only as strings in `RouteServiceRegistry::ADAPTERS`. If the selected adapter is missing, it reports which package to install.
- Adapters depend on the framework, never on each other.
- Shared test fixtures live in the framework's tests (`Crystoline\Xpsr\Tests\Fixtures\`). Fixtures that need the skeleton live in the root `tests/`.

Known coupling: the `setup` command's presets name the skeleton's route files (`routes/api.php`, `routes/web.php`), and its routing template matches the skeleton's `config/routing.php`. This belongs in an installer package once `xpsr new` exists.

## Versions

`master` is the development line of the next minor release, currently `0.3.x-dev`. When a release starts a new line, such as 0.4, change every occurrence below in one commit.

- **In this repository**, the root path repository assigns that version through its `versions` option, so it does not depend on Git branch detection.
- **In the split repositories**, each `composer.json` declares the branch alias `dev-master` → `0.3.x-dev`.
- **Between packages**, requirements use `^0.3@dev`. Composer ignores stability flags in dependencies, so they also accept the stable `0.3.0` once it is tagged.
- **The released skeleton** requires `^0.3`: `export-skeleton.php --release` writes that range.
- **`setup`** derives the adapter constraint from the installed framework: `^0.3` for a 0.3.x release, `^0.3@dev` for a development install. Adapters therefore always match the framework's release line.

`master` moved to `0.2.x-dev` with the namespace change, so that a project following `0.1.x-dev` did not receive it, and to `0.3.x-dev` with the 0.3.0 release.

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
| Framework unit tests | `packages/framework/tests` (`Crystoline\Xpsr\Tests\`) |
| Adapter tests (the shared router contract) | `packages/router-*/tests` |
| Tests that need the skeleton's files: application boot, console, generators, setup, front controller | `tests/` (`Tests\`) |
| Installing packages separately and `composer create-project` | `tests/Packaging` (group `packaging`, excluded from `composer test`) |

Each package has its own `phpunit.xml.dist` and runs its tests standalone after `composer install` in its directory. The packaging test does exactly that.

## Exporting the skeleton

```sh
php scripts/export-skeleton.php /path/to/empty/dir
```

The script copies the files a new application needs, including the application test scaffold (`tests/TestCase.php`, `tests/Application/`, and a `phpunit.xml` from `scripts/skeleton/`). It writes a `composer.json` without the monorepo-only parts:

- the `packages/*` path repository;
- autoloading for package tests;
- the analysis and style tooling and their scripts.

It does not copy `composer.lock`; see [Publishing](#publishing) for why the skeleton ships without one. `--release=X.Y.Z` writes release constraints (`^X.Y`) on the XPSR packages instead of `^0.2@dev`.

The packaging test builds a project from this export with `composer create-project`, using the packages through path repositories without symbolic links. It then checks that:

- only the FastRoute adapter is installed;
- `setup` works;
- a real `composer require` of the Sunrise adapter succeeds, including moving `psr/http-message` from 2.x to 1.x;
- requests are served and generators work;
- the project's own tests pass;
- a `composer install --no-dev --optimize-autoloader` production install still serves requests.

`XPSR_DEPENDENCIES=lowest composer test:packaging` installs each package at the lowest versions its constraints allow.

## Lowest dependency versions

The CI job `lowest-dependencies` runs the whole suite after `composer update --prefer-lowest --prefer-stable`, with deprecation notices treated as failures. This guards the minimum versions declared in each `composer.json`. It found that `vlucas/phpdotenv` 5.4, `nyholm/psr7` 1.8.0, `filp/whoops` 2.14, and `symfony/var-dumper` 6.4 print PHP 8.4 deprecation notices that corrupt responses and console output. The minimums are therefore `^5.6.1`, `^1.8.2`, and `^2.16`, plus a `conflict` with `symfony/var-dumper` below 7.4. On PHP 8.5, Whoops before 2.18.4 also uses a deprecated `(integer)` cast, so the Whoops minimum is now `^2.18.4`. The HTTP client package conflicts with `symfony/polyfill-php83` below 1.30, which Symfony HttpClient would otherwise allow and which declares implicitly nullable parameters. For the same reason the validation package conflicts with `symfony/translation-contracts` below 3.5 and with `symfony/property-info` below 6.4.8 and in 7.0.

## Publishing

`scripts/split.sh` publishes the monorepo into ten read-only repositories, and the `split` job in `.github/workflows/ci.yml` runs it after every other CI job passes:

| Repository | Content | Packagist package |
|---|---|---|
| `crystoline/xpsr-framework` | `packages/framework`, with its history (`git subtree split`) | `crystoline/xpsr-framework` |
| `crystoline/xpsr-router-fastroute` | `packages/router-fastroute`, with its history | `crystoline/xpsr-router-fastroute` |
| `crystoline/xpsr-router-sunrise` | `packages/router-sunrise`, with its history | `crystoline/xpsr-router-sunrise` |
| `crystoline/xpsr-cache` | `packages/cache`, with its history | `crystoline/xpsr-cache` |
| `crystoline/xpsr-http-client` | `packages/http-client`, with its history | `crystoline/xpsr-http-client` |
| `crystoline/xpsr-database` | `packages/database`, with its history | `crystoline/xpsr-database` |
| `crystoline/xpsr-orm-doctrine` | `packages/orm-doctrine`, with its history | `crystoline/xpsr-orm-doctrine` |
| `crystoline/xpsr-log-monolog` | `packages/log-monolog`, with its history | `crystoline/xpsr-log-monolog` |
| `crystoline/xpsr-validation` | `packages/validation`, with its history | `crystoline/xpsr-validation` |
| `crystoline/xpsr-skeleton` | the output of `scripts/export-skeleton.php`, one sync commit per change | `crystoline/xpsr` |

Each repository is named after its Composer package without the vendor prefix. The skeleton is the exception: its package is `crystoline/xpsr`, so that `composer create-project crystoline/xpsr` works, but its repository is `xpsr-skeleton`, because `crystoline/xpsr` is this monorepo. Development, issues, and pull requests happen in `crystoline/xpsr`. The other ten repositories are read-only mirrors.

- **A push to `master`** updates each repository's `master` branch. Packages declare the branch alias `dev-master` → `0.3.x-dev`, so `^0.3@dev` resolves to it.
- **A `v*` tag** pushes that tag to every repository and leaves branches alone. The skeleton's tagged commit is exported with `--release`, so it requires the release range (`^0.3`), while its `master` keeps `^0.3@dev`.
- **Re-running** with the same commit changes nothing.
- **Inputs:** everything comes from the committed tree, never the working directory.

The skeleton ships without `composer.lock`: each new application resolves its dependencies at `create-project` time and commits its own lock. This replaces the original plan's "commit the skeleton's lockfile". A shipped lock would pin every new application to the versions current at release time.

### Rehearsal

The split and release flow was rehearsed against local bare repositories:

- Splitting twice produced identical commits.
- A release tagged every repository.
- `composer create-project` from them (as VCS repositories) installed framework and FastRoute `0.1.0`, and the application's tests passed.
- `setup --router=sunrise --install-dependencies` installed the Sunrise adapter `0.1.0` and moved `psr/http-message` to 1.1.
- A `0.1.x-dev` project resolved the `master` branches through the branch aliases.

To repeat it locally:

```sh
for r in xpsr-framework xpsr-router-fastroute xpsr-router-sunrise xpsr-cache xpsr-skeleton; do git init --bare /tmp/remotes/$r.git; done
scripts/split.sh /tmp/remotes master          # branch sync
scripts/split.sh /tmp/remotes master v0.1.0   # release
```

### First publication

Owner-only steps, in order:

1. **Repositories.** Create five empty public repositories under `crystoline`: `xpsr-framework`, `xpsr-router-fastroute`, `xpsr-router-sunrise`, `xpsr-cache`, and `xpsr-skeleton`. Describe each as a read-only mirror of `crystoline/xpsr`, and consider turning off issues and pull requests there.
2. **Token.** Create a fine-grained personal access token with access to those five repositories only, with **Contents: read and write**. Store it as the `SPLIT_TOKEN` Actions secret of `crystoline/xpsr`: Settings → Secrets and variables → Actions.
3. **First sync.** Merge `framework-rebuild` into `master` and push. When CI passes, the `split` job publishes the five `master` branches.
4. **Old prototype.** In Packagist, delete the `v1.0.0` version of `crystoline/xpsr` (the old prototype), and delete the `v1.0.0` tag in `crystoline/xpsr`. Change the package's repository URL to `https://github.com/crystoline/xpsr-skeleton`.
5. **Packagist.** Submit `crystoline/xpsr-framework`, `crystoline/xpsr-router-fastroute`, `crystoline/xpsr-router-sunrise`, and `crystoline/xpsr-cache` with their repositories. Enable Packagist's GitHub integration so all five update automatically.
6. **Release.** Move the `CHANGELOG.md` entries under `0.1.0`, commit, then tag `v0.1.0` in `crystoline/xpsr` and push the tag. CI tags all five mirrors.
7. **Check.** In an empty directory, run `composer create-project crystoline/xpsr my-app` and confirm it installs `0.1.0`, including `xpsr-framework` 0.1.0.

### Adding a package

A new package can be developed and tested in `packages/` before it has a mirror. Wire it into the monorepo the way `packages/cache` is:

- `composer.json` in the package, with the `dev-master` branch alias of the current line (see [Versions](#versions)), its own `phpunit.xml.dist`, `.gitignore`, `LICENSE`, and `README.md`;
- the root `composer.json`: `require-dev`, the path repository's `versions`, and `autoload-dev` for its tests;
- `phpunit.xml` (a test suite and a `source` exclusion), `phpstan.neon` (its `src` and `tests`), and the packages list in `tests/Packaging/PackagesTest.php`;
- `MONOREPO_DEV_PACKAGES` in `scripts/export-skeleton.php`, so the skeleton does not require it.

`packages/auth-jwt`, `packages/orm-eloquent`, `packages/queue`, and `packages/mail` have no repository yet, so the split skips them (they are not in `PUBLISHED`).

`scripts/split.sh` publishes only the packages in its `PUBLISHED` list, so an unpublished package does not break the `split` job. To publish one:

1. **Repository.** Create an empty public repository `crystoline/xpsr-<name>` and describe it as a read-only mirror.
2. **Token.** Give the `SPLIT_TOKEN` token **Contents: read and write** access to the new repository too.
3. **Split.** Add `<name>` to `PUBLISHED` in `scripts/split.sh`, add the package to the publishing table above and to the skeleton's `suggest` if applications should discover it, commit, and push. CI publishes its `master`.
4. **Packagist.** Submit `https://github.com/crystoline/xpsr-<name>`. The next release tag then tags it with the other packages.
