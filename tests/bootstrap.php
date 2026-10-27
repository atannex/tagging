<?php

$autoload = getenv('TAGGING_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Run composer install, or set TAGGING_TEST_AUTOLOAD to a compatible Composer autoloader.');
}
$loader = require $autoload;
$loader->addPsr4('Atannex\\Tagging\\', __DIR__.'/../src');
$loader->addPsr4('Atannex\\Tagging\\Tests\\', __DIR__);
