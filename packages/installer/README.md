# crystoline/xpsr-installer

The `xpsr` command outside a project. `xpsr new` creates an XPSR application in one step, and any other command runs the nearest project's own `xpsr`, so `php xpsr serve` becomes `xpsr serve`.

```sh
composer global require crystoline/xpsr-installer
```

Composer's global `bin` directory must be on your `PATH`: `composer global config bin-dir --absolute` prints it.

## Creating a project

```sh
xpsr new my-app
cd my-app
php xpsr serve
```

`xpsr new` runs `composer create-project crystoline/xpsr my-app`, then the new project's own `php xpsr setup` with the router and preset, which installs the adapter and checks that the application boots. In a terminal it asks for the directory, the router, and the preset; the options answer them in advance:

| Option | What it does |
|---|---|
| `--router=fastroute\|sunrise` | The router adapter; `fastroute` by default. |
| `--preset=web\|api` | The route files: `web` (the default) loads `routes/web.php` and `routes/api.php`; `api` loads `routes/api.php` only. |
| `--git` | Initialises a git repository and commits the new project. A missing git, or a commit that fails because git has no `user.name` and `user.email`, is reported and leaves the project as it is. |
| `--dev` | The development line (`dev-master`) instead of the latest release. |
| `--release=0.5.1` | A release or constraint to install. |
| `--repository=<json or URL>` | A Composer repository to install from, kept in the project, for packages that are not on Packagist. May be repeated. |
| `--composer=/path/to/composer.phar` | Composer, when it is not on the `PATH`. |
| `--no-interaction` | Takes the defaults for anything not given. |

```sh
xpsr new my-app --router=sunrise --preset=api --git --no-interaction
```

The installer knows nothing about the project's files: it only runs Composer and the project's commands, so one installed version keeps working with every XPSR release. Exit codes: 0 when the project is ready; 2 for an unknown router or preset, or `--dev` with `--release`; 1 when the directory is not empty, Composer is missing or fails (the directory may then hold a partial project), or the project's setup fails (the project exists; run `php xpsr setup` in it after fixing the cause).

## Running a project's commands

Inside a project, or any directory below it, every command that is not `new` goes to the project:

```sh
xpsr serve
xpsr migrate
xpsr make:resource PaymentResource
```

The project is the nearest directory above the current one with an `xpsr` file and a `composer.json` that requires `crystoline/xpsr-framework`; outside one, the installer says so. The command gets the terminal, so it can show progress and ask questions, except on Windows, where a command that asks questions needs `php xpsr` or `--no-interaction`.

`xpsr list`, `xpsr help new`, and `xpsr --version` describe the installer itself.
