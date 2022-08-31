<?php

namespace mpcmf\system\storage;

use Envms\FluentPDO\Query;
use mpcmf\system\helper\io\log;
use mpcmf\system\pattern\factory;
use mpcmf\system\storage\exception\storageException;
use PDO;
use mpcmf\system\storage\interfaces\storageInterface;

class fluentInstance implements storageInterface
{
    use factory, log;

    /** @var PDO */
    private $storageInstance;

    private $map = [];

    public function getStorageDriver(): PDO
    {
        if ($this->storageInstance === null) {
            $config = $this->getPackageConfig();

            $config['sql_type'] = $config['sql_type'] ?: 'mysql';
            switch ($config['sql_type']) {
                case 'sqlite':
                    $this->storageInstance = new PDO("{$config['sql_type']}:{$config['db_file']}.sqlite3");
                    break;
                case 'mysql':
                    $uri = "{$config['sql_type']}:host={$config['host']};port={$config['port']};charset=UTF8";
                    //$options = [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_bin'"];
                    //$options = isset($config['options']) ? array_replace($options, $config['options']) : $options;
                    //$this->storageInstance = new PDO($uri, $config['username'], $config['password'], $options);
                    $this->storageInstance = new PDO($uri, $config['username'], $config['password'], $config['options'] ?? null);
                    break;
                default:
                    throw new storageException("Invalid SQL storage type: {$config['sql_type']}");
            }

            $this->storageInstance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->storageInstance;
    }

    public function getMongo(): PDO
    {
        return $this->getStorageDriver();
    }

    public function select($db, $collection, $criteria = [], $fields = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $mysqlRequest = $this->getCollection($db, $collection)->from()->where($where);

        return new fluentCursor($mysqlRequest);
    }

    public function selectOne($db, $collection, $criteria = [], $fields = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $select = $this->getCollection($db, $collection)->from();
        $select->where($where)->limit(1);
        MPCMF_DEBUG && self::log()->addDebug("QUERY: {$select->getQuery(false)}", [__METHOD__]);
        $row = $select->fetch();
        if($row === null || $row === false) {

            return null;
        }

        return $row;
    }

    public function selectAndModify($db, $collection, $criteria, $newObject, $selectFields = [], $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function selectAndModifyFields($db, $collection, $criteria, $modifyFields, $selectFields = [], $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function update($db, $collection, $criteria, $newObject, $options = [])
    {
        $mongo2sql = mongo2sql::getInstance();
        $where = $mongo2sql->translateCriteria($criteria);
        $newData = $mongo2sql->translateUpdatePayload($newObject);
        if(empty($newData)) {

            return false;
        }
        $newData = $this->castTypes($this->map[$db][$collection], $newData);

        return $this->getCollection($db, $collection)->update(null, $newData)->where($where)->execute();
    }

    public function updateFields($db, $collection, $criteria, $fields, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function removeOne($db, $collection, $criteria = [], $options = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $mysqlResult = $this->getCollection($db, $collection)->delete()->where($where)->limit(1);

        return $mysqlResult->execute();
    }

    public function remove($db, $collection, $criteria = [], $options = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);

        return $this->getCollection($db, $collection)->delete()->where($where)->execute();
    }

    protected function castTypes($map, $object)
    {
        foreach ($object as $field => &$value) {
            if($field === '_id') {
                continue;
            }
            $fieldMap = $map[$field];
            $value = $this->castType($fieldMap['type'], $value);
        }
        unset($value);

        return $object;
    }
    protected function castType($mapperType, $value)
    {
        switch ($mapperType) {
            case 'string':
                return (string)$value;
            case 'int':
                return (int)$value;
            case 'boolean':
                return (int)$value;
            default:
                throw new storageException("Unsupported field type `{$mapperType}` for conversion to sql");
        }
    }

    public function insert($db, $collection, $object, $options = [])
    {
        $object = $this->castTypes($this->map[$db][$collection], $object);
        try {
            $result = $this->getCollection($db, $collection)->insertInto(null, $object)->execute();
        } catch (\PDOException $exception) {

            throw new storageException('[' . __METHOD__ . '] ' . "PDOException: {$exception->getMessage()}");
        }
        return $result;
    }

    public function insertBatch($db, $collection, $objects, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function save($db, $collection, $object, $options = [])
    {
        try {
            $result = $this->getCollection($db, $collection)
                           ->insertInto(null, $object)
                           ->ignore()
                           ->onDuplicateKeyUpdate($object)
                           ->execute();
        } catch (\PDOException $exception) {

            throw new storageException('[' . __METHOD__ . '] ' . "PDOException: {$exception->getMessage()}");
        }
        return $result;
    }

    public function getCollectionName($db, $collection)
    {
        $config = $this->getPackageConfig();
        if($config['sql_type'] === 'sqlite') {
            return "{$db}_{$collection}";
        }

        return $collection;
    }

    public function getCollection($db, $collection):Query
    {
        $collection = $this->getCollectionName($db, $collection);
        //@TODO: need to validate somehow if table exists. Or dont need?
        return $this->getDb($db)->setTableName($collection, $db, '.');
    }

    public function getDb($db):Query
    {
        /** @var []Query $databases */
        static $databases = [];
        $config = $this->getPackageConfig();
        if($config['sql_type'] === 'sqlite') {
            //@NOTE: sqlite has only one db 
            $db = $config['db_file'];
        }
        if(!isset($databases[$db])) {
            $databases[$db] = new Query($this->getStorageDriver());
        }

        return $databases[$db];
    }

    public function checkIndexes($db, $collection, $indexes)
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function checkIndicesAuto($config)
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function setPrimary($db, $collection, $id)
    {
        //@TODO: save as property and use in select
    }

    public function setMap($db, $collection, $map)
    {
        if(!isset($this->map[$db])) {
            $this->map[$db] = [];
        }
        $this->map[$db][$collection] = $map;
    }

    public function generateSchema($db, $collection)
    {
        // TODO: Implement generateSchema() method.
    }
}