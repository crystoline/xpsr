<?php

declare(strict_types=1);

use Crystoline\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application(dirname(__DIR__));
$application->boot();

return $application;
