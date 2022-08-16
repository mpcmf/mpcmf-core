<?php

namespace mpcmf\modules\moduleBase\models;

use mpcmf\system\storage\storageCursor;

/**
 * Model cursor abstraction class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
class modelCursor
    implements \Iterator
{
    private $count;

    private $skip = 0;

    private $limit = 0;

    /**
     * @var storageCursor
     */
    private $cursor;
    /**
     * @var modelBase $modelClass
     */
    private $modelClass;

    /**
     * @param string|modelBase  $modelClass
     * @param storageCursor     $cursor
     */
    public function __construct($modelClass, storageCursor $cursor = null)
    {
        $this->setCursor($cursor);
        $this->modelClass = $modelClass;
    }

    /**
     * @param storageCursor $cursor
     */
    public function setCursor(storageCursor $cursor)
    {
        $this->cursor = $cursor;
    }

    /**
     * @return storageCursor
     */
    public function getCursor()
    {
        return $this->cursor;
    }

    public function count($force = false)
    {
        if($force || !isset($this->count)) {
            $this->count = $this->cursor->count();
        }

        return $this->count;
    }

    /**
     * Returns the current element
     *
     * @return modelBase
     */
    public function current()
    {
        $modelClass = $this->modelClass;
        return new $modelClass($this->cursor->current());
    }

    /**
     * Return the next object to which this cursor points, and advance the cursor
     *
     * @throws \MongoConnectionException
     * @throws \MongoCursorTimeoutException
     * @return modelBase Returns the next object
     */
    public function getNext()
    {
        $modelClass = $this->modelClass;
        $item = $this->cursor->getNext();
        return isset($item) ? (new $modelClass($item)) : null;
    }

    /**
     * @param $method
     * @param $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return call_user_func_array([$this->cursor, $method], $arguments);
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Move forward to next element
     *
     * @link http://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     */
    public function next()
    {
        $this->cursor->next();
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Return the key of the current element
     *
     * @link http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     */
    public function key()
    {
        return $this->cursor->key();
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Checks if current position is valid
     *
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     *       Returns true on success or false on failure.
     */
    public function valid()
    {
        return $this->cursor->valid();
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Rewind the Iterator to the first element
     *
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     */
    public function rewind()
    {
        $this->cursor->rewind();
    }

    /**
     * Export all items as array
     *
     * @return array[]
     */
    public function export()
    {
        return iterator_to_array($this->cursor);
    }

    /**
     * Export all item as models (slower than export)
     *
     * @return modelBase[]
     */
    public function exportModels()
    {
        $result = [];
        $modelClass = $this->modelClass;
        foreach($this->cursor as $key => $item) {
            $result[$key] = $modelClass::fromArray($item);
        }

        return $result;
    }

    /**
     * @param int $num
     *
     * @return \MongoCursor
     *
     * @throws \MongoCursorException
     */
    public function skip($num)
    {
        $this->skip = (int) $num;

        return $this->cursor->skip($this->skip);
    }

    /**
     * @param int $num
     *
     * @return \MongoCursor
     *
     * @throws \MongoCursorException
     */
    public function limit($num)
    {
        $this->limit = (int) $num;

        return $this->cursor->limit($this->limit);
    }

    /**
     *
     * @return array
     */
    public function info()
    {
        $info = $this->cursor->info();

        return $info;
    }

    public function getCurrentSkip()
    {
        return $this->skip;
    }

    public function getCurrentLimit()
    {
        return $this->limit;
    }

    public function getNextSkip()
    {
        return $this->getCurrentSkip() + $this->getCurrentLimit();
    }

    public function getPrevSkip()
    {
        $prevSkip = $this->getCurrentSkip() - $this->getCurrentLimit();
        return $prevSkip <= 0 ? 0 : $prevSkip;
    }

    public function hasNextSkip()
    {
        return $this->getNextSkip() < $this->count();
    }

    public function hasPrevSkip()
    {
        return $this->getCurrentSkip() > 0;
    }
}