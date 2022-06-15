<?php


use Crystoline\Xpsr\Application;

define('__SITEPATH__', dirname(__DIR__));// SITE FULL PATH

//set_include_path(get_include_path() . PATH_SEPARATOR .__SITEPATH__.PATH_SEPARATOR.__SITEPATH__."library") ;
require_once ('../vendor/autoload.php');

$app = new Application(__SITEPATH__);
$app->boot();
