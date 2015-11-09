<?php

namespace mpcmf\modules\moduleBase\controllers;


/**
 * Controller interface
 *
 * @author Oleg Andreev <ustr3ts@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
interface controllerInterface
{

    /**
     * Controller CRUD `create` action
     *
     * @return array|null
     */
    public function __crudCreate();

    /**
     * Controller CRUD `get` action
     *
     * @param $input
     * @return array|null
     */
    public function __crudGet($input = null);

    /**
     * Controller CRUD `update` action
     *
     * @param $input
     * @return array|null
     */
    public function __crudUpdate($input = null);

    /**
     * Controller CRUD `remove` action
     *
     * @param $input
     * @return array|null
     */
    public function __crudRemove($input = null);

    /**
     * Controller CRUD `list` action
     *
     * @return array|null
     */
    public function __crudList();
}