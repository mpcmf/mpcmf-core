<?php

namespace mpcmf\system\storage\interfaces;

interface mpcmfCursor extends \Iterator 
{
    public function skip($num);

    public function limit($num);

    public function count();
}