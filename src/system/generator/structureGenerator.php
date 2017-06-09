<?php

namespace mpcmf\system\generator;

use mpcmf\system\generator\exception\structureGeneratorException;
use mpcmf\system\helper\io\log;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class structureGenerator
 *
 * @package mpcmf\system\generator
 * @author greevex
 * @author Maxim Borovikov <maxim.mahi@gmail.com>
 * @date: 3/10/15 4:31 PM
 */
class structureGenerator
{
    use log;

    const TYPE_FILE = 'file';
    const TYPE_DIRECTORY = 'directory';
    const TYPE_GENERATOR = 'generator';

    /** @var  array $structure Structure for generating */
    protected $structure = [];

    public function __construct($structure)
    {
        $this->setStructure($structure);
    }

    public function setStructure($structure)
    {
        if (!is_array($structure)) {
            throw new structureGeneratorException('Structure should be an array!');
        }

        foreach ($structure as $item) {
            $this->checkStructureItemCorrectness($item);
        }

        $this->structure = $structure;
    }

    /**
     * Main generate method
     *
     * @throws structureGeneratorException
     */
    public function generate()
    {
        foreach($this->structure as $item) {
            MPCMF_DEBUG && self::log()->addDebug("Processing item: {$item['path']}{$item['name']}");
            switch($item['type']) {
                case self::TYPE_FILE:
                    MPCMF_DEBUG && self::log()->addDebug('Found item type: ' . self::TYPE_FILE);
                    $this->checkFile($item);
                    $this->processItemMeta($item);
                    break;
                case self::TYPE_DIRECTORY:
                    MPCMF_DEBUG && self::log()->addDebug('Found item type: ' . self::TYPE_DIRECTORY);
                    $this->checkDirectory($item);
                    break;
                case self::TYPE_GENERATOR:
                    //@todo Implement processing type generator
                    throw new structureGeneratorException('Processing meta `generator` nod implemented yet!');
                    break;
                default:
                    throw new structureGeneratorException('Unknown generate item type: ', $item['type']);
                    break;
            }
        }
    }

    /**
     * @param $item
     *
     * @throws structureGeneratorException
     */
    protected function processItemMeta($item)
    {
        if(!isset($item['meta'])) {
            throw new structureGeneratorException('Unable to find `meta` section of item');
        }
        foreach($item['meta'] as $metaType => $metaItem) {
            switch($metaType) {
                case 'content':
                    $this->processItemContent($item);
                    break;
                default:
                    throw new structureGeneratorException("Unknown meta type: {$metaType}");
                    break;
            }
        }
    }

    /**
     * Generate file path from structure item data
     *
     * @param array $item
     * @return mixed
     */
    protected function getFilePath($item)
    {
        $path = "{$item['root_path']}/{$item['path']}/{$item['name']}";

        return preg_replace('~[\\\/]+~', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Check file existence & create it if not existence
     *
     * @param array $item
     * @throws structureGeneratorException
     */
    protected function checkFile($item)
    {
        $filePath = $this->getFilePath($item);
        if(file_exists($filePath)) {
            throw new structureGeneratorException("File already exists: {$filePath}!");
        }
        $this->getFileSystem()->dumpFile($filePath, '', $item['chmod']);
    }

    /**
     * Check dir existence & create it if not existence
     *
     * @param array $item
     * @throws structureGeneratorException
     */
    protected function checkDirectory($item)
    {
        $filePath = $this->getFilePath($item);

        if(file_exists($filePath)) {
            throw new structureGeneratorException("Directory already exists: {$filePath}!");
        }
        $this->getFileSystem()->mkdir($filePath, $item['chmod']);
    }

    /**
     * @return Filesystem
     */
    protected function getFileSystem()
    {
        static $filesystem;

        if($filesystem === null) {
            $filesystem = new Filesystem();
        }

        return $filesystem;
    }

    /**
     * Process structure item content
     *
     * @param array $item
     * @throws structureGeneratorException
     */
    protected function processItemContent($item)
    {
        $filePath = $this->getFilePath($item);

        $metaContent = $item['meta']['content'];

        switch($metaContent['type']) {
            case 'callable':
                $callBack = $metaContent['value'];
                if (!is_callable($callBack)) {
                    throw new structureGeneratorException('Invalid content callable!');
                }
                $content = $callBack($item);

                if (!is_string($content)) {
                    throw new structureGeneratorException('Content generate callable returns non-string value');
                }
                file_put_contents($filePath, $content);
                break;
            case 'write':
                file_put_contents($filePath, $metaContent['value']);
                break;
            case 'append':
                file_put_contents($filePath, $metaContent['value'], FILE_APPEND);
                break;
            case 'prepend':
                //@todo piupiu
                file_put_contents($filePath, $metaContent['value'] . file_get_contents($filePath));
                break;
            case 'empty':
                file_put_contents($filePath, '');
                break;
            default:
                throw new structureGeneratorException("Unknown content type `{$metaContent['type']}`");
                break;
        }
    }

    /**
     * Check structure item correctness
     *
     * @param array $item
     * @throws structureGeneratorException
     */
    protected function checkStructureItemCorrectness($item)
    {
        $required = [
            'root_path',
            'path',
            'name',
            'chmod',
            'meta',
        ];

        foreach ($required as $field) {
            if (!isset($item[$field])) {
                throw new structureGeneratorException("Missing field `{$field}`! Structure item: " . json_encode($item));
            }
        }

        if (!is_array($item['meta'])) {
            throw new structureGeneratorException('Field `meta` should be an array! Structure item: ' . json_encode($item));
        }
    }
}