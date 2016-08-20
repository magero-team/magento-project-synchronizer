<?php

$file = __DIR__ . '/../vendor/autoload.php';
if (!$loader = file_exists($file) ? include $file : false) {
    echo 'You must set up the project dependencies using `composer install`' . PHP_EOL;
    exit(1);
}

return $loader;
