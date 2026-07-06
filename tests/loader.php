<?php

ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(-1);

$autoloadPath = __DIR__.'/../vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    echo 'Run "composer install" to install dependencies.'.PHP_EOL;
    exit(1);
}

require_once $autoloadPath;
