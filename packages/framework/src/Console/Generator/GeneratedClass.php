<?php

declare(strict_types=1);

namespace Crystoline\Console\Generator;

/** A validated generator target: where the class lives and where its file goes. */
final readonly class GeneratedClass
{
    public function __construct(
        public string $namespace,
        public string $shortName,
        public string $path,
        public string $relativePath,
    ) {}

    public function className(): string
    {
        return $this->namespace . '\\' . $this->shortName;
    }
}
