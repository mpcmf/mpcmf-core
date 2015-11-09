<?php

namespace mpcmf\system\helper\acl;

use mpcmf\modules\authex\mappers\groupMapper;
use mpcmf\system\helper\acl\exception\aclHelperException;

/**
 * ACL helper library
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
trait aclHelper
{
    protected $aclGroups = [];

    /**
     * Get acl group alias list used in this module
     *
     * @return string[]|null
     */
    public function registerAclGroups()
    {
        $this->aclGroups = array_replace(groupMapper::getInstance()->getDefaultGroups(), $this->aclGroups);
    }

    /**
     * @param string $alias
     * @param string $name
     *
     * @return mixed
     * @throws aclHelperException
     */
    protected function registerAclGroup($alias, $name)
    {
        if(isset($this->aclGroups[$alias])) {
            throw new aclHelperException("ACL group `{$alias}` already registered!");
        }

        $this->aclGroups[$alias] = [
            groupMapper::FIELD__GROUP_ALIAS => $alias,
            groupMapper::FIELD__GROUP_NAME => $name,
        ];
    }
}