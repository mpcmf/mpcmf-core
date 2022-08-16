<?php

namespace mpcmf\system\storage;

interface mpcmfCursor extends \Iterator 
{
    public function skip($num);

    public function limit($num);

    public function count();
}