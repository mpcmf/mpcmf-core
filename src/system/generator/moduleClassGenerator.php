<?php

namespace mpcmf\system\generator;

use mpcmf\system\helper\io\log;

/**
 * Class moduleClassGenerator
 *
 * Generate module class content
 *
 * @package mpcmf\system\generator
 * @author Maxim Borovikov <maxim.mahi@gmail.com>
 * @date: 3/10/15 4:31 PM
 */
class moduleClassGenerator
    extends mpcmfClassGenerator
{
    use log;

    /**
     * Generate php string
     *
     * @param array $structureItem
     * @return string
     */
    public function generate($structureItem)
    {
        $this->parseStructureItem($structureItem);
        $this->initializeGenerateParams();

        $codeGenerator = $this->getCodeGenerator();

        $content = $codeGenerator->generate();

        return $this->normalizePhpContent($content);
    }

    protected function initializeGenerateParams()
    {
        $this->skeletonName = 'mpcmf\system\skeleton\webApplicationSkeleton\modules\baseModuleSkeleton\module';
        $this->className = 'module';
    }
}