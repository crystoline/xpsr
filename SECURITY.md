# Security policy

## Reporting a vulnerability

Email **crystoline+xpsr@gmail.com**. Please do not open a public issue or pull request for a vulnerability, or describe it anywhere public, before a fix is released.

Include what you can of:

- the package and version, such as `crystoline/xpsr-database` 0.5.0;
- what an attacker can do, and what they need first, such as a signed-in account;
- the steps or code that show it.

## What happens next

- **Within 3 working days**, a reply that the report arrived. Without one, write again: the first email may not have arrived.
- **Within 10 working days**, whether it is a vulnerability, and what happens next.
- **Within 30 days** of that, a release with the fix; sooner for a severe one. If a fix needs longer, you are told why, and when to expect it.

When the fix is released, [CHANGELOG.md](CHANGELOG.md) describes the vulnerability and names you, unless you would rather not be named.

## Supported versions

While XPSR is below 1.0, vulnerabilities are fixed in the latest minor version. The fix is released at once as a patch release of it, such as 0.5.1, without waiting for the next minor release. Earlier minor versions get no fixes: [UPGRADE.md](UPGRADE.md) has the steps to the latest one.

## Scope

This policy covers the packages developed in [crystoline/xpsr](https://github.com/crystoline/xpsr): `crystoline/xpsr`, the skeleton, and every `crystoline/xpsr-*` package, including their read-only mirrors.

A vulnerability in a library XPSR uses, such as Symfony, Doctrine, Monolog, or Laravel's components, belongs to that library's maintainers. Tell us too when the way XPSR uses it makes an application vulnerable.
