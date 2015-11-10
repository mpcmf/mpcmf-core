<?php

namespace mpcmf\system\generator;

use mpcmf\system\generator\exception\moduleGeneratorException;
use mpcmf\system\helper\io\log;

/**
 * Class moduleGenerator
 *
 * @package mpcmf\system\generator
 * @author greevex
 * @author Maxim Borovikov <maxim.mahi@gmail.com>
 * @date: 3/10/15 4:31 PM
 */
class moduleGenerator
{
    use log;

    private $moduleData;

    /**
     * @param $moduleData
     *
     * @throws moduleGeneratorException
     */
    public function __construct($moduleData)
    {
        $this->setModuleData($moduleData);
    }

    /**
     *
     *
     * @param $moduleData
     *
     * @throws moduleGeneratorException
     */
    public function setModuleData($moduleData)
    {
        if(!isset($moduleData['name'])) {
            throw new moduleGeneratorException('moduleData check failed: `name` not exists!');
        }
        if(!isset($moduleData['namespace'])) {
            throw new moduleGeneratorException('moduleData check failed: `namespace` not exists!');
        }
        if(!isset($moduleData['path'])) {
            throw new moduleGeneratorException('moduleData check failed: `path` not exists!');
        }
        $this->moduleData = $moduleData;
    }

    /**
     *
     *
     * @return array
     */
    protected function getStructure()
    {
        static $structure;
        if($structure === null) {
            $structure  = [
                [
                    'path' => '/',
                    'name' => 'module.php',
                    'type' => structureGenerator::TYPE_FILE,
                    'chmod' => 0644,
                    'meta' => [
                        'content' => [
                            'type' => 'callable',
                            'value' => [new moduleClassGenerator(), 'generate']
                        ]
                    ]
                ],
//                [
//                    'path' => '/',
//                    'name' => 'routes.php',
//                    'type' => self::TYPE_FILE,
//                    'chmod' => 0644,
//                    'meta' => [
//                        'content' => [
//                            'type' => 'callable',
//                            'value' => [$this, 'generateRoutesFileContent']
//                        ]
//                    ]
//                ],
//                [
//                    'path' => '/',
//                    'name' => 'public',
//                    'type' => self::TYPE_DIRECTORY,
//                    'chmod' => 0644,
//                    'meta' => [
//
//                    ]
//                ],
//                [
//                    'path' => '/public/',
//                    'name' => '.gitignore',
//                    'type' => self::TYPE_FILE,
//                    'chmod' => 0644,
//                    'meta' => [
//                        'content' => [
//                            'type' => 'empty'
//                        ]
//                    ]
//                ]
            ];
        }

        foreach($structure as &$item) {
            $item['root_path'] = $this->moduleData['path'];
            $item['namespace'] = $this->moduleData['namespace'];
        }

        return $structure;
    }

    public function generate()
    {
        $structureGenerator = new structureGenerator($this->getStructure());
        $structureGenerator->generate();
    }
}