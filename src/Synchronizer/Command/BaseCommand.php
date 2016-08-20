<?php

namespace Magero\Project\Synchronizer\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem;
use Magero\Project\Synchronizer\Console\Application;

/**
 * Class BaseCommand
 * @package Magero\Project\Synchronizer\Command
 *
 * @method Application getApplication()
 */
abstract class BaseCommand extends Command
{
    /** @var Filesystem\Filesystem */
    protected $fileSystem;

    /**
     * BaseCommand constructor
     */
    public function __construct()
    {
        $name = get_class($this);
        $name = strtolower(str_replace('Command', '', substr($name, strrpos($name, '\\') + 1)));

        parent::__construct($name);

        $this->fileSystem = new Filesystem\Filesystem();
    }
}
