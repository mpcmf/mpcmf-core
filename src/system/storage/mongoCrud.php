<?php

namespace mpcmf\system\storage;

use mpcmf\system\configuration\config;
use mpcmf\system\configuration\exception\configurationException;
use mpcmf\system\storage\exception\storageException;
use mpcmf\system\storage\interfaces\storageInterface;

trait mongoCrud
{

    /**
     * @var storageInterface
     */
    private $storageInstance;

    /**
     * @var \MongoCollection
     */
    private $collection;

    private $mongoCrudStorageConfig;

    private static $mapSet = [];

    /**
     * @return mongoInstance
     * @throws storageException
     */
    public function storage()
    {
        if($this->storageInstance === null) {
            $config = config::getConfig(get_called_class());
            $this->mongoCrudStorageConfig = $config['storage'];
            if(!array_key_exists('configSection', $this->mongoCrudStorageConfig)) {
                throw new storageException('Unable to find config[storage][configSection] in case of mongoCrud usage');
            }
            $storageClass = $this->mongoCrudStorageConfig['type'] ?? mongoInstance::class;
            
            if(!class_exists($storageClass)) {
                throw new storageException("Invalid storage type: {$storageClass}");
            }
            $this->storageInstance = $storageClass::factory($this->mongoCrudStorageConfig['configSection']);

            try {
                //@TODO: this is hack because setMap creates recursion because getNormalizedMap creates instance of mapper and mapper may be 'related' to self
                if(!isset(self::$mapSet[$this->mongoCrudStorageConfig['db']][$this->mongoCrudStorageConfig['collection']])) {
                    self::$mapSet[$this->mongoCrudStorageConfig['db']][$this->mongoCrudStorageConfig['collection']] = true;
                    $this->storageInstance->setMap($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $this->getNormalizedMap());
                }
            } catch (configurationException $configurationException) {
                //invalid mapper config, pass
            }
        }

        return $this->storageInstance;
    }

    /**
     * @return mixed
     */
    public function getMongoCrudStorageConfig()
    {
        return $this->mongoCrudStorageConfig;
    }

    /**
     * @param $storageInstance
     * @param array $storageConfig
     *
     * @throws storageException
     */
    protected function setStorageInstance($storageInstance, array $storageConfig = null)
    {
        if(!$storageInstance instanceof mongoInstance) {
            throw new storageException("invalid instance\n" . var_export($storageInstance, true));
        }
        if($storageConfig !== null && is_array($storageConfig)) {
            $this->mongoCrudStorageConfig = $storageConfig;
        }
        $this->storageInstance = $storageInstance;
    }

    /**
     * @return \MongoCollection
     *
     * @throws storageException
     * @throws \mpcmf\system\configuration\exception\configurationException
     * @throws \MongoConnectionException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function collection()
    {
        if ($this->collection === null) {
            $storage = $this->storage();
            $config = $this->getMongoCrudStorageConfig();

            $this->collection = $storage->getCollection($config['db'], $config['collection']);
        }

        return $this->collection;
    }

    /**
     * Get native mongo collection object
     *
     * @return \MongoCollection
     * @throws configurationException
     * @throws \MongoConnectionException
     * @throws \InvalidArgumentException
     * @throws \Exception
     * @throws storageException
     */
    public function getMongoCollection()
    {
        return $this->storage()->getCollection($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection']);
    }

    /**
     * Create new item in the storage
     *
     * @param mixed $input
     * @param array $options
     *
     * @return mixed
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \MongoException
     * @throws \Exception
     */
    public function create($input, array $options = [])
    {
        return $this->storage()->insert($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $input, $options);
    }

    /**
     * Save an item to storage
     *
     * @param mixed $input
     * @param array $options
     *
     * @return mixed
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \MongoException
     * @throws \Exception
     */
    public function save($input, array $options = [])
    {
        return $this->storage()->save($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $input, $options);
    }

    /**
     * Loading item from storage by mongo-like criteria
     *
     * @param array $criteria
     * @param array $fields
     *
     * @return storageCursorWrapper
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getAllBy($criteria, array $fields = [])
    {
        $cursor = $this->storage()->select($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, $fields);

        return new storageCursorWrapper($cursor);
    }

    /**
     * Loading item from storage by mongo-like criteria
     *
     * @param array $criteria
     * @param array $fields
     *
     * @return array|mixed|null
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function getBy($criteria, array $fields = [])
    {
        return $this->storage()->selectOne($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, $fields);
    }

    /**
     * Updates all items in storage by criteria
     *
     * @param array $criteria mongo-like criteria
     * @param mixed $newData
     * @param array $options
     *
     * @return mixed
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \Exception
     */
    public function updateAllBy($criteria, $newData, array $options = [])
    {
        $options = array_replace($options, ['multiple' => true]);

        return $this->storage()->update($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, ['$set' => $newData], $options);
    }

    /**
     * Updates single item in storage by criteria
     *
     * @param array $criteria mongo-like criteria
     * @param mixed $newData
     * @param array $options
     *
     * @return mixed
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \Exception
     */
    public function updateBy($criteria, $newData, array $options = [])
    {
        $options = array_replace($options, ['multiple' => false]);

        return $this->storage()->update($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, ['$set' => $newData], $options);
    }

    /**
     * Removes all items from storage by criteria
     *
     * @param array $criteria mongo-like criteria
     * @param array $options
     *
     * @return mixed
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \MongoCursorException
     * @throws \MongoCursorTimeoutException
     * @throws \Exception
     */
    public function remove($criteria, array $options = [])
    {
        return $this->storage()->remove($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, $options);
    }

    /**
     * @param $criteria
     * @param $update
     * @param array $fields
     * @param array $options
     *
     * @return array
     *
     * @throws storageException
     * @throws \MongoConnectionException
     * @throws configurationException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function findAndModify($criteria, $update, array $fields = [], array $options = [])
    {
        return $this->storage()->selectAndModifyFields($this->mongoCrudStorageConfig['db'], $this->mongoCrudStorageConfig['collection'], $criteria, $update, $fields, $options);
    }
}