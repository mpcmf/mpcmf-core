<?php

namespace mpcmf\system\acl;

use mpcmf\modules\authex\models\userModel;
use mpcmf\modules\moduleBase\actions\action;

/**
 * Base system ACL manager
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
interface aclManagerInterface
{

    /**
     * @param action $action
     * @param string $tokenString
     * @param bool $checkLimits
     *
     * @return array Response
     */
    public function checkActionAccessByToken(action $action, $tokenString, $checkLimits = true);

    /**
     * @param action $action
     * @param userModel $userModel
     *
     * @return array Response
     */
    public function checkActionAccess(action $action, userModel $userModel);

    /**
     * @param mixed $data
     *
     * @return string
     */
    public function generateSign($data);
}