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
└── (suggested) crystoline/xpsr-router-sunrise   packages/router-sunrise ──► framework, sunrise/http-router
```

| Package | Namespace | Depends on |
|---|---|---|
| `crystoline/xpsr-framework` | `Crystoline\` | PSR interfaces, Symfony Console/Process/Finder, Illuminate Config, phpdotenv, Nyholm PSR-7 server, and any PSR-17 implementation (`psr/http-factory-implementation`). Accepts `psr/http-message` 1.1 or 2.0. Suggests Twig (views) and Whoops (debug pages), but does not require them. |
| `crystoline/xpsr-router-fastroute` | `Crystoline\Router\FastRoute\` | framework, `nikic/fast-route` ^1.3 |
| `crystoline/xpsr-router-sunrise` | `Crystoline\Router\Sunrise\` | framework, `sunrise/http-router` ^2.16, `psr/http-message` ^1.1 |
| `crystoline/xpsr` | `App\` | framework, FastRoute adapter, `nyholm/psr7`, `twig/twig`; development: `filp/whoops`, `phpunit/phpunit` |

Rules that keep the split clean:

- The framework must not reference `App\`, skeleton paths, or adapter classes. It knows the official adapter names (`fastroute`, `sunrise`) and their class names only as strings in `RouteServiceRegistry::ADAPTERS`. If the selected adapter is missing, it reports which package to install.
- Adapters depend on the framework, never on each other.
- Shared test fixtures live in the framework's tests (`Crystoline\Tests\Fixtures\`). Fixtures that need the skeleton live in the root `tests/`.

Known coupling: the `setup` command's presets name the skeleton's route files (`routes/api.php`, `routes/web.php`), and its routing template matches the skeleton's `config/routing.php`. This belongs in an installer package once `xpsr new` exists.

## Versions

All packages are `0.1.x-dev` until the first release:

- **In this repository**, the root path repository assigns that version through its `versions` option, so it does not depend on Git branch detection.
- **In the split repositories**, each `composer.json` declares the branch alias `dev-master` → `0.1.x-dev`.
- **Between packages**, requirements use `^0.1@dev`. Composer ignores stability flags in dependencies, so they also accept the stable `0.1.0` once it is tagged.
- **The released skeleton** requires `^0.1`: `export-skeleton.php --release` writes that range.
- **`setup`** derives the adapter constraint from the installed framework: `^0.1` for a 0.1.x release, `^0.1@dev` for a development install. Adapters therefore always match the framework's release line.

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

The script copies the files a new application needs, including the application test scaffold (`tests/TestCase.php`, `tests/Application/`, and a `phpunit.xml` from `scripts/skeleton/`). It writes a `composer.json` without the monorepo-only parts:

- the `packages/*` path repository;
- autoloading for package tests;
- the analysis and style tooling and their scripts.

It does not copy `composer.lock`; see [Publishing](#publishing) for why the skeleton ships without one. `--release=X.Y.Z` writes release constraints (`^X.Y`) on the XPSR packages instead of `^0.1@dev`.

The packaging test builds a project from this export with `composer create-project`, using the packages through path repositories without symbolic links. It then checks that:

- only the FastRoute adapter is installed;
- `setup` works;
- a real `composer require` of the Sunrise adapter succeeds, including moving `psr/http-message` from 2.x to 1.x;
- requests are served and generators work;
- the project's own tests pass;
- a `composer install --no-dev --optimize-autoloader` production install still serves requests.

`XPSR_DEPENDENCIES=lowest composer test:packaging` installs each package at the lowest versions its constraints allow.

## Lowest dependency versions

The CI job `lowest-dependencies` runs the whole suite after `composer update --prefer-lowest --prefer-stable`, with deprecation notices treated as failures. This guards the minimum versions declared in each `composer.json`. It found that `vlucas/phpdotenv` 5.4, `nyholm/psr7` 1.8.0, `filp/whoops` 2.14, and `symfony/var-dumper` 6.4 print PHP 8.4 deprecation notices that corrupt responses and console output. The minimums are therefore `^5.6.1`, `^1.8.2`, and `^2.16`, plus a `conflict` with `symfony/var-dumper` below 7.4.

## Publishing

`scripts/split.sh` publishes the monorepo into four read-only repositories, and the `split` job in `.github/workflows/ci.yml` runs it after every other CI job passes:

| Repository | Content | Packagist package |
|---|---|---|
| `xpsr-php/framework` | `packages/framework`, with its history (`git subtree split`) | `crystoline/xpsr-framework` |
| `xpsr-php/router-fastroute` | `packages/router-fastroute`, with its history | `crystoline/xpsr-router-fastroute` |
| `xpsr-php/router-sunrise` | `packages/router-sunrise`, with its history | `crystoline/xpsr-router-sunrise` |
| `xpsr-php/skeleton` | the output of `scripts/export-skeleton.php`, one sync commit per change | `crystoline/xpsr` |

- **A push to `master`** updates each repository's `master` branch. Packages declare the branch alias `dev-master` → `0.1.x-dev`, so `^0.1@dev` resolves to it.
- **A `v*` tag** pushes that tag to every repository and leaves branches alone. The skeleton's tagged commit is exported with `--release`, so it requires the release range (`^0.1`), while its `master` keeps `^0.1@dev`.
- **Re-running** with the same commit changes nothing.
- **Inputs:** everything comes from the committed tree, never the working directory.

The skeleton ships without `composer.lock`: each new application resolves its dependencies at `create-project` time and commits its own lock. This replaces the original plan's "commit the skeleton's lockfile". A shipped lock would pin every new application to the versions current at release time.

### Rehearsal

The split and release flow was rehearsed against local bare repositories:

- Splitting twice produced identical commits.
- A release tagged all four repositories.
- `composer create-project` from them (as VCS repositories) installed framework and FastRoute `0.1.0`, and the application's tests passed.
- `setup --router=sunrise --install-dependencies` installed the Sunrise adapter `0.1.0` and moved `psr/http-message` to 1.1.
- A `0.1.x-dev` project resolved the `master` branches through the branch aliases.

To repeat it locally:

```sh
for r in framework router-fastroute router-sunrise skeleton; do git init --bare /tmp/remotes/$r.git; done
scripts/split.sh /tmp/remotes master          # branch sync
scripts/split.sh /tmp/remotes master v0.1.0   # release
```

### First publication

Owner-only steps, in order:

1. **Organization and repositories.** Create the `xpsr-php` organization. Transfer this repository to `xpsr-php/xpsr`; GitHub redirects the old URL. Create empty `framework`, `router-fastroute`, `router-sunrise`, and `skeleton` repositories. Mark the four as read-only in their descriptions and point contributors to `xpsr-php/xpsr`.
2. **Token.** Create a fine-grained token, or a GitHub App, with **Contents: read and write** on those four repositories only. Store it as the `SPLIT_TOKEN` secret of `xpsr-php/xpsr`.
3. **First sync.** Merge `framework-rebuild` into `master` and push. CI then publishes the four `master` branches.
4. **Old prototype.** In Packagist, delete the `v1.0.0` version of `crystoline/xpsr` (the old prototype), delete its tag in the old repository, and change the package's repository URL to `https://github.com/xpsr-php/skeleton`.
5. **Packagist.** Submit `crystoline/xpsr-framework`, `crystoline/xpsr-router-fastroute`, and `crystoline/xpsr-router-sunrise` with their split repositories. Enable the GitHub hook (Packagist's GitHub integration) for all four.
6. **Release.** Move the `CHANGELOG.md` entries under `0.1.0`, commit, then tag `v0.1.0` in `xpsr-php/xpsr` and push the tag. CI tags all four repositories.
7. **Check.** In an empty directory, run `composer create-project crystoline/xpsr my-app` and confirm it installs `0.1.0`, including `xpsr-framework` 0.1.0.
