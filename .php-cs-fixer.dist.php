<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/packages',
        __DIR__ . '/app',
        __DIR__ . '/bootstrap',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->append([
        __DIR__ . '/public/index.php',
        __DIR__ . '/xpsr',
        __DIR__ . '/config/app.php',
        __DIR__ . '/config/factory.php',
        __DIR__ . '/config/routing.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRules(['@PER-CS3x0' => true])
    ->setFinder($finder);
