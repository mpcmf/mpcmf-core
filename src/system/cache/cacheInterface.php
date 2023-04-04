<?php

namespace mpcmf\system\cache;

interface cacheInterface
{
    public function set($key, $value, $expire = 60);

    public function touch($key, $expire= 60);

    public function add($key, $value, $expire = 60);

    public function get($key);

    public function exists($key);

    public function remove($key);

    public function inc($key, $howMany = 1, $initial = 0, $expire = 0);

    public function dec($key, $howMany = 1, $initial = 0, $expire = 0);
}