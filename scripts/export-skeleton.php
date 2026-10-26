<?php

declare(strict_types=1);

/*
 * Export the application skeleton (crystoline/xpsr) from this monorepo.
 *
 *   php scripts/export-skeleton.php <empty target directory>
 *
 * Copies only the files a new application needs, and writes a composer.json without the
 * monorepo-only parts: the path repository for packages/, autoloading for package tests,
 * and development tooling whose configuration is not exported. composer.lock is not
 * copied: a released skeleton's lock file must be built against published packages.
 */

const SKELETON_PATHS = [
    '.docker', '.dockerignore', '.env.example', '.gitattributes', '.gitignore', 'LICENSE',
    'app', 'bootstrap', 'config', 'docker-compose.yml', 'public', 'routes', 'views', 'xpsr',
];

/** require-dev entries and scripts that only make sense inside the monorepo. */
const MONOREPO_DEV_PACKAGES = ['crystoline/xpsr-router-sunrise', 'friendsofphp/php-cs-fixer', 'phpstan/phpstan', 'phpunit/phpunit'];
const MONOREPO_SCRIPTS = ['test', 'test:packaging', 'test:coverage', 'analyse', 'style', 'style:fix', 'check'];

$source = dirname(__DIR__);
$target = $argv[1] ?? '';
if ($target === '' || (file_exists($target) && (!is_dir($target) || count(scandir($target)) > 2))) {
    fwrite(STDERR, "Usage: php scripts/export-skeleton.php <empty target directory>\n");
    exit(2);
}
if (!is_dir($target) && !mkdir($target, 0777, true)) {
    fwrite(STDERR, "Could not create {$target}.\n");
    exit(1);
}

function copyPath(string $from, string $to): void
{
    if (is_link($from)) {
        throw new RuntimeException("Refusing to export a symbolic link: {$from}");
    }
    if (is_dir($from)) {
        mkdir($to);
        foreach (scandir($from) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                copyPath("{$from}/{$entry}", "{$to}/{$entry}");
            }
        }

        return;
    }
    copy($from, $to);
    chmod($to, fileperms($from) & 0777);
}

foreach (SKELETON_PATHS as $path) {
    if (file_exists("{$source}/{$path}")) {
        copyPath("{$source}/{$path}", "{$target}/{$path}");
    }
}

$composer = json_decode((string) file_get_contents("{$source}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
unset($composer['repositories'], $composer['autoload-dev']);
foreach (MONOREPO_DEV_PACKAGES as $package) {
    unset($composer['require-dev'][$package]);
}
if (($composer['require-dev'] ?? []) === []) {
    unset($composer['require-dev']);
}
foreach (MONOREPO_SCRIPTS as $script) {
    unset($composer['scripts'][$script]);
}
file_put_contents(
    "{$target}/composer.json",
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
);

echo "Exported the skeleton to {$target}\n";
