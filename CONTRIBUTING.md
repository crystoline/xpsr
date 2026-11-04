# Contributing to XPSR

XPSR is developed in one repository, [crystoline/xpsr](https://github.com/crystoline/xpsr): the application skeleton at its root and every package under `packages/`. The `crystoline/xpsr-*` repositories are read-only mirrors of those packages, published for Composer. Issues and pull requests go to `crystoline/xpsr`.

## Reporting a bug

Open an issue with the package and version (`php xpsr about` prints the framework version), what you did, what happened, and what you expected. The smallest route, command, or test that shows the problem is the fastest way to a fix.

A vulnerability is not an issue: [SECURITY.md](SECURITY.md) says where to send it.

## Proposing a change

Open an issue before writing a large change, so that the design is settled first. XPSR chooses its conventions on their merits rather than copying another framework's, so say what an application gains. Some features are left out on purpose, with the reason, under [Left out on purpose](docs/IMPLEMENTATION_STATUS.md#left-out-on-purpose) in the build log; check it before proposing one of them.

## Setting up

PHP 8.4 or newer and Composer 2. The tests need the DOM, XML, XMLWriter, and mbstring extensions; `serve` and the live server tests need pcntl on Unix.

```sh
composer install
php xpsr setup
composer serve
```

`composer install` links `packages/*` into `vendor/crystoline/` through a Composer path repository, so an edit under `packages/` takes effect at once. After changing a package's `composer.json`, run `composer update 'crystoline/*'`.

Without a local PHP 8.4, the development container has one:

```sh
docker compose build
docker compose run --rm app composer install
docker compose up -d                              # http://localhost:8088/
docker compose run --rm app composer check
```

## Checks

```sh
composer test               # the framework, package, and skeleton suites
composer analyse            # PHPStan, level 5
composer style              # PER Coding Style; composer style:fix applies it
composer check              # all three
composer audit
composer test:packaging     # installs each package on its own and builds a project; needs the network
```

`composer test:coverage` needs a coverage driver. CI runs the checks on PHP 8.4 and 8.5, the packaging tests, and the whole suite once more after `composer update --prefer-lowest --prefer-stable`, with deprecation notices treated as failures: a minimum version in any `composer.json` must be free of them on every supported PHP version. That is why some minimums are higher than the API needs, such as `vlucas/phpdotenv` 5.6.1, `nyholm/psr7` 1.8.2, and `filp/whoops` 2.18.4, and why a few packages declare `conflict` entries against versions that print notices.

## Where tests go

| Test | Location |
|---|---|
| Framework unit tests | `packages/framework/tests` (`Crystoline\Xpsr\Tests\`) |
| A package's tests | `packages/<name>/tests` |
| Router adapters, with the shared contract suite | `packages/router-*/tests`, extending `Crystoline\Xpsr\Testing\RouterContractTestCase` |
| Tests that need the skeleton's files: application boot, console, generators, setup, the front controller | `tests/` (`Tests\`) |
| Installing packages separately and `composer create-project` | `tests/Packaging` (group `packaging`, run by `composer test:packaging` only) |

Shared fixtures live in the framework's tests (`Crystoline\Xpsr\Tests\Fixtures\`); fixtures that need the skeleton live in the root `tests/`. Each package has its own `phpunit.xml.dist` and runs its tests on its own after `composer install` in its directory, which is what the packaging test does.

The database packages run their tests on SQLite, and on MySQL 8.4, MariaDB 11, and PostgreSQL 17 when `XPSR_TEST_MYSQL_URL` or `XPSR_TEST_PGSQL_URL` is set.

## Rules between packages

- The framework never references `App\`, the skeleton's paths, or an adapter class. It knows the official adapters (`fastroute`, `sunrise`) only as names in `RouteServiceRegistry::ADAPTERS`, and reports which package to install when the selected one is missing.
- Adapters depend on the framework, never on each other.
- An optional package depends on the framework and on the packages it needs; it detects the others at run time where it can use them.
- The `setup` presets name the skeleton's route files and match its `config/routing.php`. That is deliberate: the installer runs the project's own `setup`, so the version that owns the files is the one that knows them.

## Documentation

The guides in `docs/` and the package READMEs are the documentation, on GitHub and at [xpsr.crysto.name.ng](https://xpsr.crysto.name.ng/docs/). `docs/README.md` is the website's sidebar: each `##` heading is a section and each listed file a page; a file that is not listed stays on GitHub only.

They are written for the people who build applications with XPSR, and say what an application gets, how to use it, and what is not provided. A few habits keep them that way:

- What was tested is stated as what works ("works on SQLite, MySQL 8.4, MariaDB 11, and PostgreSQL 17"), not as a description of the test suite.
- Plans, the measurements behind a design, and decisions belong in [docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md), the build log, not in a guide. A feature that is missing is stated under "Not provided", without "yet".
- Nothing refers to how XPSR worked before a release.
- Every change an application can notice gets a line under **Unreleased** in [CHANGELOG.md](CHANGELOG.md); a breaking change also gets its steps in [UPGRADE.md](UPGRADE.md).

## Pull requests

- One change per pull request, with tests, and `composer check` passing.
- A commit message is a sentence saying what the commit does.
- Pull requests to the mirror repositories cannot be merged: send them here.
- Contributions are licensed under the [MIT License](LICENSE), like XPSR.

## Adding a package

A package is developed and tested in `packages/<name>` before it is published. Wire it in the way `packages/cache` is:

- `composer.json` in the package, with the `dev-master` branch alias of the current line, its own `phpunit.xml.dist`, `.gitignore`, `LICENSE`, and `README.md`;
- the root `composer.json`: `require-dev`, the path repository's `versions`, and `autoload-dev` for its tests;
- `phpunit.xml` (a test suite and a `source` exclusion), `phpstan.neon` (its `src` and `tests`), and the packages list in `tests/Packaging/PackagesTest.php`;
- `MONOREPO_DEV_PACKAGES` in `scripts/export-skeleton.php`, so the skeleton does not require it;
- `docs/README.md`, so that the README is on the website, and the tables in `README.md` and `docs/PACKAGES.md`.

`scripts/split.sh` publishes only the packages in its `PUBLISHED` list, so an unpublished package does not break the `split` job. Publishing one is a maintainer's step, below.

## Releasing (maintainers)

Every package is released with the same version number ([docs/VERSIONING.md](docs/VERSIONING.md)).

1. Move the **Unreleased** entries in `CHANGELOG.md` under `## X.Y.Z (date)`, check that `UPGRADE.md` covers every breaking change, and commit.
2. Make sure CI is green, including the packaging and lowest-dependency jobs.
3. For a new minor line, such as 0.8, move the line in one commit: the `dev-master` branch alias in every package's `composer.json` (`0.8.x-dev`), the requirements between packages (`^0.8@dev`), the `versions` of the root path repository, and the places that name the line in the documentation and tests; then run `composer update 'crystoline/*'`. `setup` derives the adapter constraint from the installed framework, and `export-skeleton.php --release` writes the release range, so neither needs a change.
4. Tag `vX.Y.Z` and push the tag. The `split` job tags every mirror and exports the skeleton with `--release`, so the tagged skeleton requires `^X.Y`.
5. Packagist updates the packages through the GitHub hook; if the skeleton's entry lags, press **Update** on it. Then, in an empty directory, `composer create-project crystoline/xpsr my-app` must install the new version.

A vulnerability is fixed in a patch release of the latest minor version at once ([SECURITY.md](SECURITY.md)); other fixes wait for the next release.

### How publishing works

`scripts/split.sh` publishes the monorepo into the mirrors, and the `split` job in `.github/workflows/ci.yml` runs it after every other job passes. Each package's mirror is `crystoline/xpsr-<name>`, holding `packages/<name>` with its history (`git subtree split`), and Packagist serves the package of the same name from it. The skeleton is the exception: its package is `crystoline/xpsr`, so that `composer create-project crystoline/xpsr` works, and its mirror is `crystoline/xpsr-skeleton`, because `crystoline/xpsr` on GitHub is this repository. That mirror holds the output of `scripts/export-skeleton.php`, one commit per change.

- A push to `master` updates each mirror's `master`. The packages alias `dev-master` to the current line (`0.7.x-dev`), so `^0.7@dev` resolves to it.
- A `v*` tag pushes that tag to every mirror and leaves branches alone. The skeleton's tagged commit is exported with `--release`, so it requires the release range, while its `master` keeps `^0.7@dev`.
- Re-running with the same commit changes nothing, and everything comes from the committed tree, never the working directory.
- The skeleton ships without `composer.lock`: each new application resolves its dependencies at `create-project` time and commits its own lock, instead of being pinned to the versions of release day.

`scripts/split.sh <remotes> master` syncs the branches and `scripts/split.sh <remotes> master vX.Y.Z` tags, into any directory of bare repositories named like the mirrors, which is how the flow is tried locally.

### Exporting the skeleton

```sh
php scripts/export-skeleton.php /path/to/empty/dir                   # development constraints (^0.7@dev)
php scripts/export-skeleton.php /path/to/empty/dir --release=0.7.0   # release constraints (^0.7)
```

It copies what a new application needs, including the test scaffold (`tests/TestCase.php`, `tests/Application/`, and a `phpunit.xml` from `scripts/skeleton/`), and writes a `composer.json` without the monorepo-only parts: the `packages/*` path repository, the autoloading of package tests, the analysis and style tooling and their scripts, and the packages in `MONOREPO_DEV_PACKAGES`.

The packaging test builds a project from this export with `composer create-project`, through path repositories without symbolic links, and checks that only the FastRoute adapter is installed, that `setup` works, that `composer require` of the Sunrise adapter succeeds, that requests are served and generators work, that the project's own tests pass, and that a `composer install --no-dev --optimize-autoloader` install still serves requests. `XPSR_DEPENDENCIES=lowest composer test:packaging` installs each package at the lowest versions its constraints allow.

### Publishing a new package

1. Create an empty public repository `crystoline/xpsr-<name>`, described as a read-only mirror of `crystoline/xpsr`, with issues and pull requests off.
2. Give the `SPLIT_TOKEN` secret of `crystoline/xpsr` (a fine-grained token with **Contents: read and write**) access to it.
3. Add `<name>` to `PUBLISHED` in `scripts/split.sh`, and the package to the skeleton's `suggest` if applications should discover it; commit and push. CI publishes its `master`.
4. Submit `https://github.com/crystoline/xpsr-<name>` on Packagist. The next release tag then tags it with the other packages.
