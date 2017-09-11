<?php

namespace mpcmf\system\acl;

use mpcmf\modules\moduleBase\actions\action;
use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\exceptions\modelException;
use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\modules\moduleBase\models\modelCursor;
use mpcmf\system\acl\exception\aclException;
use mpcmf\system\configuration\config;
use mpcmf\system\configuration\environment;
use mpcmf\system\helper\io\response;
use mpcmf\system\pattern\singleton;
use Slim\Slim;

/**
 * Base system ACL manager
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class aclManager
{
    use singleton, response;

    const ACL__GROUP_ROOT = 'root';
    const ACL__GROUP_ADMIN = 'admin';
    const ACL__GROUP_USER = 'user';
    const ACL__GROUP_GUEST = 'guest';

    const ACL__GROUP_CRUD_FULL = 'crud.full';
    const ACL__GROUP_CRUD_READ = 'crud.read';
    const ACL__GROUP_CRUD_WRITE = 'crud.write';

    const ACL__GROUP_API_FULL = 'api.full';
    const ACL__GROUP_API_READ = 'api.read';
    const ACL__GROUP_API_WRITE = 'api.write';

    /** @var aclManagerInterface */
    protected $aclManager;

    private $cookie = [
        'name' => 'mpcmf:user',
        'expire' => '+1 day'
    ];

    public function __construct()
    {
        $config = config::getConfig(__CLASS__);
        $this->cookie = $config['cookie'];

        $this->cookie['name'] .= ':' . crc32(environment::getCurrentEnvironment());

        $aclClass = $config['acl_class'];
        $this->aclManager = new $aclClass();

        if (!($this->aclManager instanceof aclManagerInterface)) {
            throw new aclException("{$aclClass} is not implements aclManagerInterface");
        }
    }

    /**
     * @param action $action
     * @param string $tokenString
     * @param bool $checkLimits
     *
     * @return array Response
     */
    public function checkActionAccessByToken(action $action, $tokenString, $checkLimits = true)
    {
        return $this->aclManager->checkActionAccessByToken($action, $tokenString, $checkLimits);
    }

    /**
     * @param action $action
     *
     * @return array Response
     *
     * @throws mapperException
     * @throws aclException
     */
    public function checkActionAccess(action $action)
    {
        return $this->aclManager->checkActionAccess($action, $this->getCurrentUser());
    }

    /**
     * @param mixed $data
     *
     * @return string
     */
    public function generateSign($data)
    {
        return $this->aclManager->generateSign($data);
    }

    public function createGroupsByList($entityAclGroups)
    {
        return $this->aclManager->createGroupsByList($entityAclGroups);
    }

    /**
     * @return modelBase
     *
     * @throws mapperException
     * @throws aclException
     */
    public function getCurrentUser()
    {
        $cookieData = $this->getCookieData();

        return $this->aclManager->getCurrentUser($cookieData);
    }

    /**
     * @return mixed|null
     * @throws aclException
     */
    public function getCookieData()
    {
        $cookieDataString = Slim::getInstance()->getEncryptedCookie($this->cookie['name']);
        if(!$cookieDataString) {

            return null;
        }
        $cookieData = json_decode($cookieDataString, true);
        if(!is_array($cookieData) || !isset($cookieData['sign'])) {
            throw new aclException('Invalid cookie data');
        }

        if($cookieData['sign'] !== $this->generateSign($cookieData)) {
            throw new aclException('Invalid cookie sign');
        }

        return $cookieData;
    }

    /**
     * @param array $cookieData
     */
    public function setCookieData($cookieData)
    {
        $cookieData['sign'] = $this->generateSign($cookieData);

        Slim::getInstance()->setEncryptedCookie($this->cookie['name'], json_encode($cookieData), $this->cookie['expire']);
    }

    /**
     * @param modelBase $user
     *
     * @throws modelException
     */
    public function saveUserCookie($user)
    {
        $cookieData = $this->aclManager->buildCookieDataByUser($user);

        $this->setCookieData($cookieData);
    }

    public function removeUserCookie()
    {
        Slim::getInstance()->deleteCookie($this->cookie['name']);
    }

    /**
     * Get all expanded group ids
     *
     * @param $cursor
     *
     * @return mixed
     * @throws \mpcmf\modules\moduleBase\exceptions\modelException
     */
    public function expandGroupsByCursor(modelCursor $cursor)
    {
        return $this->aclManager->expandGroupsByCursor($cursor);
    }
}