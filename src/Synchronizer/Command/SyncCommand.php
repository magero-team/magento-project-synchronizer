<?php
/**
 *  This file is part of the Magero Project Synchronizer.
 *
 *  (c) Magero team <support@magero.pw>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace Magero\Project\Synchronizer\Command;

use Symfony\Component\Console\Exception;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Finder;
use Symfony\Component\Filesystem;

/**
 * Class SyncCommand
 * @package Magero\Project\Synchronizer\Command
 */
class SyncCommand extends BaseCommand
{
    CONST ARGUMENT_PROJECT_DIRECTORY = 'project_directory';
    CONST ARGUMENT_TARGET_DIRECTORY = 'target_directory';
    CONST OPTION_GROUP = 'group';
    CONST OPTION_SYNC_TYPE_LINKS = 'links';

    const SYNC_TYPE_FILES = 'files';
    const SYNC_TYPE_LINKS = 'links';

    /** @var string */
    private $syncType = self::SYNC_TYPE_FILES;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Synchronize project files with files in target instance directory');

        $this->addArgument(
            self::ARGUMENT_PROJECT_DIRECTORY,
            Input\InputArgument::REQUIRED,
            'The project directory files will be read from'
        );

        $this->addArgument(
            self::ARGUMENT_TARGET_DIRECTORY,
            Input\InputArgument::REQUIRED,
            'Target instance directory the project files will be placed where'
        );

        $this->addOption(
            self::OPTION_GROUP,
            'g',
            Input\InputOption::VALUE_OPTIONAL,
            'The group ownership of synced files/directories'
        );

        $this->addOption(
            self::OPTION_SYNC_TYPE_LINKS,
            'l',
            Input\InputOption::VALUE_NONE,
            'If specified, use the symlinks instead of direct copying for project files'
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(Input\InputInterface $input, Output\OutputInterface $output)
    {
        $sourceDirectory = $input->getArgument(self::ARGUMENT_PROJECT_DIRECTORY);
        if (!is_dir($sourceDirectory)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Project directory "%s" does not exist', $sourceDirectory)
            );
        }
        if (!is_readable($sourceDirectory)) {
            throw new Filesystem\Exception\IOException(
                sprintf('Project directory "%s" is not readable', $sourceDirectory)
            );
        }
        $sourceDirectory = realpath($sourceDirectory);

        $targetDirectory = $input->getArgument(self::ARGUMENT_TARGET_DIRECTORY);
        if (!is_dir($targetDirectory)) {
            throw new Exception\InvalidArgumentException(
                sprintf('Target instance directory "%s" does not exist', $targetDirectory)
            );
        }
        if (!is_writable($targetDirectory)) {
            throw new Filesystem\Exception\IOException(
                sprintf('Target instance directory "%s" is not writable', $targetDirectory)
            );
        }
        $targetDirectory = realpath($targetDirectory);

        if ($input->getOption(self::OPTION_SYNC_TYPE_LINKS)) {
            $this->syncType = self::SYNC_TYPE_LINKS;
        }

        $this->getApplication()->configureCacheDirectory($sourceDirectory, $input);

        $directories = Finder\Finder::create()->directories()->in($sourceDirectory);
        $files = Finder\Finder::create()->files()->in($sourceDirectory);

        $currentState = [
            'source_directory' => $sourceDirectory,
            'files' => [],
            'directories' => [],
        ];

        /** @var Finder\SplFileInfo $directory */
        foreach ($directories as $directory) {
            $currentState['directories'][$directory->getRelativePathname()] = $directory->getRelativePathname();
        }

        /** @var Finder\SplFileInfo $file */
        foreach ($files as $file) {
            $currentState['files'][$file->getRelativePathname()] = $file->getRelativePathname();
        }

        $cacheState = $this->getApplication()->readFromCache($sourceDirectory);
        if ($cacheState) {
            foreach (array_keys($cacheState['files']) as $fileRelativePathname) {
                $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . $fileRelativePathname;
                if (!file_exists($sourceDirectory . DIRECTORY_SEPARATOR . $fileRelativePathname) &&
                    (file_exists($targetFile) || is_link($targetFile))
                ) {
                    $this->fileSystem->remove($targetFile);
                    unset($cacheState['files'][$fileRelativePathname]);
                }
            }
            arsort($cacheState['directories']);
            foreach (array_keys($cacheState['directories']) as $directoryRelativePathname) {
                $targetDir = $targetDirectory . DIRECTORY_SEPARATOR . $directoryRelativePathname;
                if (!is_dir($sourceDirectory . DIRECTORY_SEPARATOR . $directoryRelativePathname) &&
                    is_dir($targetDir) &&
                    (Finder\Finder::create()->in($targetDir)->count() == 0)
                ) {
                    if (!@rmdir($targetDir) && is_dir($targetDir)) {
                        $error = error_get_last();
                        throw new Exception\RuntimeException(
                            sprintf('Failed to remove directory "%s": %s.', $file, $error['message'])
                        );
                    }
                }
            }
        }

        $ownershipGroup = $input->getOption(self::OPTION_GROUP);

        foreach ($currentState['directories'] as $directoryRelativePathname) {
            $targetDir = $targetDirectory . DIRECTORY_SEPARATOR . $directoryRelativePathname;
            if (!is_dir($targetDir)) {
                $this->fileSystem->mkdir($targetDir, 0770);
            }
            if ($ownershipGroup) {
                $this->tryChangeGroup($targetDir, $ownershipGroup);
            }
        }

        foreach ($currentState['files'] as $fileRelativePathname) {
            $sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . $fileRelativePathname;
            $targetFile = $targetDirectory . DIRECTORY_SEPARATOR . $fileRelativePathname;

            $this->copyFile($sourceFile, $targetFile);

            if ($ownershipGroup) {
                $this->tryChangeGroup($targetFile, $ownershipGroup);
            }
        }

        $this->getApplication()->writeToCache($sourceDirectory, $currentState);

        $output->writeln('Directory "' . $targetDirectory . '" has been updated');
    }

    /**
     * @param string $sourceFile
     * @param string $targetFile
     * @return $this
     */
    private function copyFile($sourceFile, $targetFile)
    {
        if (is_dir($targetFile)) {
            $this->fileSystem->remove($targetFile);
        }

        switch ($this->syncType) {
            case self::SYNC_TYPE_FILES:
                if (is_link($targetFile)) {
                    $this->fileSystem->remove($targetFile);
                }
                if (!file_exists($targetFile) || (md5_file($sourceFile) !== md5_file($targetFile))) {
                    $this->fileSystem->copy($sourceFile, $targetFile, true);
                }
                break;
            case self::SYNC_TYPE_LINKS:
                if (is_file($targetFile)) {
                    $this->fileSystem->remove($targetFile);
                }
                $this->fileSystem->symlink($sourceFile, $targetFile, true);
                break;
        }

        return $this;
    }

    /**
     * @param $target
     * @param $ownershipGroup
     */
    private function tryChangeGroup($target, $ownershipGroup)
    {
        if (function_exists('filegroup') && function_exists('posix_getgrgid')) {
            $group = @posix_getgrgid(filegroup($target));
            if ($group && !empty($group['name']) && ($group['name'] != $ownershipGroup)) {
                $this->fileSystem->chgrp($target, $ownershipGroup, true);
            }
        }
    }
}
