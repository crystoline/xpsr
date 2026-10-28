<?php

declare(strict_types=1);

// Event listeners, run by Psr\EventDispatcher\EventDispatcherInterface. See docs/EVENTS.md.
return [
    // Event class or interface => listeners, run in the listed order. A listener is a class
    // with an __invoke(object $event) method, a [class, method] pair, or a closure.
    'listeners' => [
        // App\Events\UserRegistered::class => [App\Listeners\SendWelcomeEmail::class],
    ],
];
