<?php

namespace mpcmf\system\application;

use Symfony\Component\Console\Application;

class consoleBase
    extends consoleApplicationBase
{
    const COMMANDS_DIRECTORY = 'commands';

    protected function getCommands()
    {
        static $commands;

        if($commands === null) {
            MPCMF_DEBUG && self::log()->addDebug('Searching commands for console toolkit...');
            $commands = [];
            $reflection = new \ReflectionClass(get_called_class());
            $directory = dirname($reflection->getFileName());
            $commandsDirectory = $directory . '/' . self::COMMANDS_DIRECTORY;

            $commands = $this->getCommandsFromDirectory($commandsDirectory, $reflection->getNamespaceName());

            MPCMF_DEBUG && self::log()->addDebug('Total found commands: ' . count($commands));
        }

        return $commands;
    }

    protected function getCommandsFromDirectory($directory, $baseNamespace)
    {
        $commands = [];

        MPCMF_DEBUG && self::log()->addDebug("Processing directory: {$directory}");
        $context = basename($directory, '.php');
        $possibleNamespace = "{$baseNamespace}\\{$context}";
        foreach(scandir($directory) as $commandFile) {
            $filePath = "{$directory}/{$commandFile}";
            if(strpos($commandFile, '.') === 0) {
                MPCMF_DEBUG && self::log()->addDebug("Skipping file: {$commandFile}");
                continue;
            } elseif(is_dir($filePath)) {
                MPCMF_DEBUG && self::log()->addDebug("Directory found, recursive processing: {$commandFile}", ['Command search']);
                $commands = array_merge($commands, $this->getCommandsFromDirectory($filePath, $possibleNamespace));
            } elseif(is_readable($filePath)) {
                MPCMF_DEBUG && self::log()->addDebug("Command file found: {$commandFile}");
                $commandName = basename($commandFile, '.php');
                $commandClass = "{$baseNamespace}\\{$context}\\{$commandName}";
                $commands[] = new $commandClass();
            }
        }

        return $commands;
    }

    protected function application()
    {
        static $application;

        if($application === null) {
            $application = new Application();
        }

        return $application;
    }

    /**
     * Handle function
     *
     * @return mixed
     */
    protected function handle()
    {
        $commands = $this->getCommands();
        $this->application()->addCommands($commands);
        $this->application()->run();
    }
}