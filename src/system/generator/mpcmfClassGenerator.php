<?php

namespace mpcmf\system\generator;

use mpcmf\system\generator\exception\mpcmfClassGeneratorException;
use mpcmf\system\helper\io\log;
use Wingu\OctopusCore\CodeGenerator\PHP\OOP\ClassGenerator;
use Wingu\OctopusCore\CodeGenerator\Exceptions\InvalidArgumentException;
use Wingu\OctopusCore\Reflection\ReflectionClass;

/**
 * Class mpcmfClassGenerator
 *
 * Generate php class for mpcmf system
 *
 * @package mpcmf\system\generator
 * @author Maxim Borovikov <maxim.mahi@gmail.com>
 * @date: 3/10/15 4:31 PM
 */
abstract class mpcmfClassGenerator
{
    use log;

    protected $namespace;
    protected $skeletonName = '\stdClass';
    protected $className = 'mpcmfDefaultClass';

    /**
     * Generate php string
     *
     * @param array $structureItem
     * @return string
     */
    abstract public function generate($structureItem);

    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    protected function parseStructureItem($structureItem)
    {
        if (!isset($structureItem['namespace'])) {
            throw new mpcmfClassGeneratorException('Missing `namespace` field in structure item!');
        }

        $this->setNamespace($structureItem['namespace']);
    }

    /**
     * Get code generator object
     *
     * @return ClassGenerator
     * @throws InvalidArgumentException
     */
    protected function getCodeGenerator()
    {
        $generator = ClassGenerator::fromReflection(new ReflectionClass($this->skeletonName));

        $generator->setNamespace($this->namespace);
        $generator->setName($this->className);

        return $generator;
    }

    /**
     * Normalize php content
     *
     * @param string $content
     *
     * @return string
     */
    protected function normalizePhpContent($content)
    {
        $content = "<?php\n\n{$content}";

        return $content;
    }
}