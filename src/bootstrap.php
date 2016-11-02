<?php
/**
 *  This file is part of the Magero Project Synchronizer.
 *
 *  (c) Magero team <support@magero.pw>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

$files = array(
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
);

$loader = false;
foreach ($files as $file) {
    if ($loader = file_exists($file) ? include $file : false) {
        break;
    }
}

if (!$loader) {
    echo 'You must set up the project dependencies using `composer install`' . PHP_EOL;
    exit(1);
}

return $loader;
