<?php

namespace mpcmf\system\storage;

use LessQL\Database;
use LessQL\Result;
use mpcmf\system\configuration\exception\configurationException;
use mpcmf\system\pattern\factory;
use mpcmf\system\storage\exception\storageException;
use PDO;

class mysqlInstance implements storageInterface
{
    use factory;

    /** @var PDO */
    private $storageInstance;

    public function getMongo(): PDO
    {
        if ($this->storageInstance === null) {
            $config = $this->getPackageConfig();

            $config['sql_type'] = $config['sql_type'] ?: 'mysql';
            switch ($config['sql_type']) {
                case 'sqlite':
                    $this->storageInstance = new PDO("{$config['sql_type']}:{$config['db']}.sqlite3");
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

    public function select($db, $collection, $criteria = [], $fields = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $mysqlResult = $this->getCollection($db, $collection)->where($where);

        return new mysqlCursor($mysqlResult);
    }

    public function selectOne($db, $collection, $criteria = [], $fields = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $mysqlResult = $this->getCollection($db, $collection)->where($where)->limit(1);
        $row = $mysqlResult->fetch();
        if($row === null) {
            
            return null;
        }

        return $row->getData();
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
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        //@TODO: what else can be except `$set`?
        $newData = $newObject['$set'] ?? $newObject;
        $mysqlResult = $this->getCollection($db, $collection)->where($where)->update($newData);
        //@TODO: check mysql response for error?
        
        return true;
    }

    public function updateFields($db, $collection, $criteria, $fields, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function removeOne($db, $collection, $criteria = [], $options = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $mysqlResult = $this->getCollection($db, $collection)->where($where)->limit(1)->delete();

        return $mysqlResult->errorCode() === 0;
    }

    public function remove($db, $collection, $criteria = [], $options = [])
    {
        $where = mongo2sql::getInstance()->translateCriteria($criteria);
        $mysqlResult = $this->getCollection($db, $collection)->where($where)->delete();

        return $mysqlResult->errorCode() === 0;
    }

    public function insert($db, $collection, $object, $options = [])
    {
        try {
            $this->getCollection($db, $collection)->insert($object);
        } catch (\PDOException $e) {
            throw new storageException($e->getMessage());
        }
        
        return true;
    }

    public function insertBatch($db, $collection, $objects, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }

    public function save($db, $collection, $object, $options = [])
    {
        throw new \Exception('method ' . __METHOD__ . ' not implemented yet for ' . __CLASS__);
    }


    public function getCollection($db, $collection):Result
    {
        return $this->getDb($db)->table($collection);
    }

    public function getDb($db):Database
    {
        static $databases = [];
        $config = $this->getPackageConfig();
        if(isset($config['sql_type']) && $config['sql_type'] === 'sqlite' && $db !== $config['db']) {
            throw new \Exception('cannot change database for sqlite');
        }
        if(!isset($databases[$db])) {
            $databases[$db] = new Database($this->getMongo());
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
        $this->getCollection($db, $collection)->getDatabase()->setPrimary($collection, $id);
    }
}