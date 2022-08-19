<?php

namespace mpcmf\system\storage;

use Envms\FluentPDO\Query;
use mpcmf\system\pattern\factory;
use mpcmf\system\storage\exception\storageException;
use PDO;
use mpcmf\system\storage\interfaces\storageInterface;

class fluentInstance implements storageInterface
{
    use factory;

    /** @var PDO */
    private $storageInstance;

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
                    $this->storageInstance = new PDO("{$config['sql_type']}:host={$config['host']}", $config['username'], $config['password'], $config['options'] ?? null);
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
        $mysqlResult = $this->getCollection($db, $collection)->from()->where($where)->limit(1);
        $row = $mysqlResult->fetch();
        if($row === null) {
            
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

    public function insert($db, $collection, $object, $options = [])
    {
        try {
            $result = $this->getCollection($db, $collection)->insertInto(null, $object)->execute();
        } catch (\PDOException $exception) {

            throw new storageException("PDOException: {$exception->getMessage()}");
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

            throw new storageException("PDOException: {$exception->getMessage()}");
        }
        return $result;
    }

    public function getCollection($db, $collection):Query
    {
        if($this->getPackageConfig()['sql_type'] === 'sqlite') {
            $collection = "{$db}_{$collection}";
        }

        //@TODO: need to validate somehow if table exists. Or dont need?
        return $this->getDb($db)->setTableName($collection);
    }

    public function getDb($db):Query
    {
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
}