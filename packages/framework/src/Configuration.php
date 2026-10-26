<?php

declare(strict_types=1);

namespace Crystoline;

use Illuminate\Config\Repository;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class Configuration extends Repository
{
    public function loadConfigurationFiles(string $path, ?string $environment = null): void
    {
        $this->items = [];

        foreach ($this->getConfigurationFiles($path) as $key => $file) {
            $this->set($key, $this->readConfiguration($file));
        }

        if ($environment === null || $environment === '') {
            return;
        }

        foreach ($this->getConfigurationFiles($path . '/' . $environment) as $key => $file) {
            foreach ($this->readConfiguration($file) as $setting => $value) {
                $this->set($key . '.' . $setting, $value);
            }
        }
    }

    /** @return array<string, string> */
    private function getConfigurationFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (Finder::create()->files()->name('*.php')->in($path)->depth(0)->sortByName() as $file) {
            $files[$file->getBasename('.php')] = $file->getPathname();
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function readConfiguration(string $file): array
    {
        $values = (static fn(string $path) => require $path)($file);

        if (!is_array($values)) {
            throw new RuntimeException(sprintf('Configuration file "%s" must return an array.', $file));
        }

        return $values;
    }
}
