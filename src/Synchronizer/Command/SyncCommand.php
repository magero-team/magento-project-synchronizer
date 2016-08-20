<?php

namespace Magero\Project\Synchronizer\Command;

use Symfony\Component\Console\Exception;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Finder;
use Symfony\Component\Filesystem;

class SyncCommand extends BaseCommand
{
    CONST ARGUMENT_PROJECT_DIRECTORY = 'project_directory';
    CONST ARGUMENT_MAGENTO_DIRECTORY = 'magento_directory';
    CONST OPTION_GROUP = 'group';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Synchronize project files with files in Magento instance directory');

        $this->addArgument(
            self::ARGUMENT_PROJECT_DIRECTORY,
            Input\InputArgument::REQUIRED,
            'The project directory files will be read from'
        );

        $this->addArgument(
            self::ARGUMENT_MAGENTO_DIRECTORY,
            Input\InputArgument::REQUIRED,
            'Magento instance directory the project files will be placed where'
        );

        $this->addOption(
            self::OPTION_GROUP,
            'g',
            Input\InputOption::VALUE_OPTIONAL,
            'The group ownership of synced files/directories'
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(Input\InputInterface $input, Output\OutputInterface $output)
    {
        $sourceDirectory = $input->getArgument(self::ARGUMENT_PROJECT_DIRECTORY);
        if (!is_dir($sourceDirectory)) {
            throw new Exception\InvalidArgumentException(sprintf('Project directory "%s" does not exist', $sourceDirectory));
        }
        if (!is_readable($sourceDirectory)) {
            throw new Filesystem\Exception\IOException(sprintf('Project directory "%s" is not readable', $sourceDirectory));
        }
        $sourceDirectory = realpath($sourceDirectory);

        $targetDirectory = $input->getArgument(self::ARGUMENT_MAGENTO_DIRECTORY);
        if (!is_dir($targetDirectory)) {
            throw new Exception\InvalidArgumentException(sprintf('Magento directory "%s" does not exist', $targetDirectory));
        }
        if (!is_writable($targetDirectory)) {
            throw new Filesystem\Exception\IOException(sprintf('Magento directory "%s" is not writable', $targetDirectory));
        }
        $targetDirectory = realpath($targetDirectory);

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
                    file_exists($targetFile)
                ) {
                    if (!@unlink($targetFile) && file_exists($targetFile)) {
                        $error = error_get_last();
                        throw new Exception\RuntimeException(
                            sprintf('Failed to remove file "%s": %s.', $targetFile, $error['message'])
                        );
                    }
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
            if (!file_exists($targetFile) || (md5_file($sourceFile) !== md5_file($targetFile))) {
                $this->fileSystem->copy($sourceFile, $targetFile, true);
            }
            if ($ownershipGroup) {
                $this->tryChangeGroup($targetFile, $ownershipGroup);
            }
        }

        $this->getApplication()->writeToCache($sourceDirectory, $currentState);

        $output->writeln('Magento instance directory has been updated');
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
