<?php

namespace mpcmf\system\storage\interfaces;

interface storageCursorInteface extends \Iterator 
{
    public function skip($num);

    public function limit($num);

    public function count();
}