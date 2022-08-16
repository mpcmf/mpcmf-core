<?php

namespace mpcmf\system\storage;

class storageCursor implements mpcmfCursor
{

    /**
     * @var \Iterator
     */
    protected $cursor;
    
    public function __construct($cursor) 
    {
        $this->cursor = $cursor;
    }

    public function current()
    {
        return $this->cursor->current();
    }

    public function next()
    {
        return $this->cursor->next();
    }

    public function key()
    {
        return $this->cursor->key();
    }

    public function valid()
    {
        return $this->cursor->valid();
    }

    public function rewind()
    {
        $this->cursor->rewind();
    }
    
    public function skip($num)
    {
        return $this->cursor->skip($num);
    }

    public function limit($num)
    {
        return $this->cursor->limit($num);
    }

    public function count()
    {
        return $this->cursor->count();
    }
}