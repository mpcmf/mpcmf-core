<?php

namespace mpcmf\system\application;

use mpcmf\system\helper\io\log;
use phpDocumentor\Reflection\DocBlock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class consoleCommandBase
    extends Command
{
    use log;

    /**
     * Define arguments
     *
     * @return mixed
     */
    abstract protected function defineArguments();

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @throws \LogicException When this abstract method is not implemented
     *
     * @see setCode()
     */
    abstract protected function handle(InputInterface $input, OutputInterface $output);

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @return null|int null or 0 if everything went fine, or an error code
     *
     * @throws \LogicException When this abstract method is not implemented
     *
     * @see setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        return $this->handle($input, $output);
    }

    protected function configure()
    {
        $reflection = new \ReflectionClass(get_called_class());

        $baseNamespaceChunks = [];

        foreach(explode('\\', $reflection->getNamespaceName()) as $namespaceChunk) {
            $baseNamespaceChunks[] = $namespaceChunk;
            if($namespaceChunk == consoleBase::COMMANDS_DIRECTORY) {
                break;
            }
        }

        $namespace = str_replace(implode('\\', $baseNamespaceChunks), '', get_called_class());
        $namespace = trim($namespace, '\\');

        $commandNameData = explode('\\', $namespace);

        $phpdoc = new DocBlock($reflection);

        /** @var DocBlock\Tag $tag */
        $tag = reset($phpdoc->getTagsByName('consoleNs'));

        $commandNameValues = [];

        if($tag) {
            $consoleNs = trim($tag->getDescription());
            if(!empty($consoleNs)) {
                $commandNameValues[] = $consoleNs;
            }
        }


        foreach($commandNameData as $commandNameValue) {
            $commandNameValues[] = $commandNameValue;
        }

        $this->setName(implode(':', $commandNameValues))
            ->setDescription($phpdoc->getShortDescription())
            ->setHelp($phpdoc->getLongDescription());

        $this->defineArguments();
    }
}