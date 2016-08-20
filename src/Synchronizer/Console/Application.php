<?php

namespace Magero\Project\Synchronizer\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Filesystem;
use Magero\Project\Synchronizer\Command;

class Application extends BaseApplication
{
    const VERSION = '1.0.0';

    /** @var   */
    private $cacheDirectory;

    public function __construct()
    {
        parent::__construct('Magero Project Synchronizer', self::VERSION);
    }

    protected function getDefaultCommands()
    {
        $commands = array_merge(
            parent::getDefaultCommands(),
            array(
                new Command\SyncCommand(),
            )
        );

        return $commands;
    }

    protected function getDefaultInputDefinition()
    {
        $definition = parent::getDefaultInputDefinition();
        $definition->addOption(new InputOption('--cache-dir', '-c', InputOption::VALUE_REQUIRED, 'If specified, use the given directory as cache directory'));

        return $definition;
    }

    /**
     * @param $sourceDirectory
     * @param InputInterface $input
     * @return string
     */
    public function configureCacheDirectory($sourceDirectory, InputInterface $input)
    {
        $fileSystem = new Filesystem\Filesystem();

        if (!$cacheDirectory = $input->getOption('cache-dir')) {
            $cacheDirectory = $sourceDirectory . DIRECTORY_SEPARATOR . '.cache';
        }
        if (!is_dir($cacheDirectory)) {
            $fileSystem->mkdir($cacheDirectory);
        }
        if (!is_readable($cacheDirectory)) {
            throw new Filesystem\Exception\IOException(sprintf('Cache directory "%s" is not readable', $cacheDirectory));
        }
        if (!is_writable($cacheDirectory)) {
            throw new Filesystem\Exception\IOException(sprintf('Cache directory "%s" is not writable', $cacheDirectory));
        }

        $this->cacheDirectory = realpath($cacheDirectory);

        return $this->cacheDirectory;
    }

    /**
     * @param string $path
     * @return mixed
     */
    public function getCacheDirectory($path = null)
    {
        if (!$this->cacheDirectory) {
            throw new RuntimeException('Cache directory in not configured yet');
        }
        if ($path) {
            return $this->cacheDirectory . DIRECTORY_SEPARATOR . trim(ltrim((string)$path, '\\\/'));
        }

        return $this->cacheDirectory;
    }

    /**
     * @param string $sourceDirectory
     * @return array|null
     */
    public function readFromCache($sourceDirectory)
    {
        $cacheFileName = $this->getCacheDirectory(md5((string)$sourceDirectory));
        if (!file_exists($cacheFileName)) {
            return null;
        }

        return unserialize(file_get_contents($cacheFileName));
    }

    /**
     * @param string $sourceDirectory
     * @param array $data
     * @return $this
     */
    public function writeToCache($sourceDirectory, array $data)
    {
        $cacheFileName = $this->getCacheDirectory(md5((string)$sourceDirectory));
        file_put_contents($cacheFileName, serialize($data));

        return $this;
    }
}
