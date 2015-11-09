<?php

namespace mpcmf\system\acl;

use mpcmf\cache;
use mpcmf\modules\authex\mappers\userMapper;
use mpcmf\modules\authex\models\userModel;
use mpcmf\modules\moduleBase\actions\action;
use mpcmf\modules\moduleBase\exceptions\mapperException;
use mpcmf\modules\moduleBase\exceptions\modelException;
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

    /**
     * @return \mpcmf\modules\moduleBase\models\modelBase|userModel
     *
     * @throws mapperException
     * @throws aclException
     */
    public function getCurrentUser()
    {
        $cookieData = $this->getCookieData();
        if(!$cookieData) {
            static $guestCacheKey = 'acl/user/guest';

            if(!($guestData = cache::getCached($guestCacheKey))) {
                $guestModel = userMapper::getInstance()->getGuestUser();
                $guestData = $guestModel->export();
                cache::setCached($guestCacheKey, $guestData, 3600);
            } else {
                $guestModel = userModel::fromArray($guestData);
            }

            return $guestModel;
        }

        $userId = $cookieData[userMapper::FIELD__USER_ID];
        $userCacheKey = "acl/user/{$userId}";
        if(!($userData = cache::getCached($userCacheKey))) {
            $userModel = userMapper::getInstance()->getById($userId);
            $userData = $userModel->export();
            cache::setCached($userCacheKey, $userData, 300);
        } else {
            $userModel = userModel::fromArray($userData);
        }

        return $userModel;
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
     * @param userModel $user
     *
     * @throws modelException
     */
    public function saveUserCookie(userModel $user)
    {
        $cookieData = [
            userMapper::FIELD__USER_ID => $user->getIdValue(),
            'name' => $user->getFirstName(),
            'groups' => $user->getGroupIds(),
            'email' => $user->getEmail()
        ];

        $this->setCookieData($cookieData);
    }

    public function removeUserCookie()
    {
        Slim::getInstance()->deleteCookie($this->cookie['name']);
    }
}