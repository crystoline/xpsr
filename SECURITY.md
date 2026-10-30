# Security policy

## Reporting a vulnerability

Email **crystoline+xpsr@gmail.com**. Please do not open a public issue or pull request for a vulnerability, or describe it anywhere public, before a fix is released.

Include what you can of:

- the package and version, such as `crystoline/xpsr-database` 0.4.0;
- what an attacker can do, and what they need first, such as a signed-in account;
- the steps or code that show it.

You will get an answer by email. When the fix is released, [CHANGELOG.md](CHANGELOG.md) describes the vulnerability and names you, unless you would rather not be named.

## Supported versions

While XPSR is below 1.0, vulnerabilities are fixed in the latest minor version, and the fix is released for it. Earlier minor versions get no fixes: [UPGRADE.md](UPGRADE.md) has the steps to the latest one.

## Scope

This policy covers the packages developed in [crystoline/xpsr](https://github.com/crystoline/xpsr): `crystoline/xpsr`, the skeleton, and every `crystoline/xpsr-*` package, including their read-only mirrors.

A vulnerability in a library XPSR uses, such as Symfony, Doctrine, Monolog, or Laravel's components, belongs to that library's maintainers. Tell us too when the way XPSR uses it makes an application vulnerable.
