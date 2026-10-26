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
use mpcmf\system\helper\io\log;
use mpcmf\system\helper\io\response;
use mpcmf\system\pattern\singleton;
use Slim\Slim;

/**
 * Base system ACL manager
 *
 * The session cookie is encrypted here (AES-256-GCM, key derived from the Slim
 * `cookies.secret_key`) because Slim 2 only encrypts with mcrypt, which is gone since PHP 7.2.
 * A cookie that fails to decrypt or to verify is dropped and the request runs as guest.
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class aclManager
{
    use singleton, response, log;

    const COOKIE_CIPHER = 'aes-256-gcm';
    const COOKIE_FORMAT_PREFIX = 'v2:';
    const COOKIE_DEFAULT_SECRETS = ['CHANGE_THIS_SECRET_KEY', 'CHANGE_ME'];

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
     * @return array|null Cookie payload, or null when there is no usable session cookie
     */
    public function getCookieData()
    {
        $slim = Slim::getInstance();
        $cookieDataString = $slim->getEncryptedCookie($this->cookie['name']);
        if(!$cookieDataString) {

            return null;
        }

        if ($slim->config('cookies.encrypt')) {
            $cookieDataString = $this->decryptCookie($cookieDataString, $slim);
        }

        $cookieData = $cookieDataString === null ? null : json_decode($cookieDataString, true);
        if(!is_array($cookieData) || !isset($cookieData['sign']) || !is_string($cookieData['sign'])
            || !hash_equals($this->generateSign($cookieData), $cookieData['sign'])) {
            // stale format, foreign secret or tampering: never trust it, never fail the request
            $this->removeUserCookie();

            return null;
        }

        return $cookieData;
    }

    /**
     * @param array $cookieData
     */
    public function setCookieData($cookieData)
    {
        $slim = Slim::getInstance();
        $cookieData['sign'] = $this->generateSign($cookieData);
        $cookieDataString = json_encode($cookieData);

        if ($slim->config('cookies.encrypt')) {
            $cookieDataString = $this->encryptCookie($cookieDataString, $slim);
        }

        $slim->setEncryptedCookie(
            $this->cookie['name'],
            $cookieDataString,
            $this->cookie['expire'],
            null,
            null,
            (bool)$slim->config('cookies.secure'),
            (bool)$slim->config('cookies.httponly')
        );
    }

    /**
     * @param string $plain
     * @param Slim   $slim
     *
     * @return string `v2:` + base64(iv . tag . ciphertext)
     * @throws aclException
     */
    protected function encryptCookie($plain, Slim $slim)
    {
        $ivLength = openssl_cipher_iv_length(self::COOKIE_CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::COOKIE_CIPHER, $this->cookieKey($slim), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new aclException('Unable to encrypt cookie: ' . openssl_error_string());
        }

        return self::COOKIE_FORMAT_PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    /**
     * @param string $encrypted
     * @param Slim   $slim
     *
     * @return string|null Plain text, or null for anything that is not a valid `v2:` cookie
     */
    protected function decryptCookie($encrypted, Slim $slim)
    {
        if (strpos($encrypted, self::COOKIE_FORMAT_PREFIX) !== 0) {
            return null;
        }
        $raw = base64_decode(substr($encrypted, strlen(self::COOKIE_FORMAT_PREFIX)), true);
        $ivLength = openssl_cipher_iv_length(self::COOKIE_CIPHER);
        $tagLength = 16;
        if ($raw === false || strlen($raw) <= $ivLength + $tagLength) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, $ivLength + $tagLength),
            self::COOKIE_CIPHER,
            $this->cookieKey($slim),
            OPENSSL_RAW_DATA,
            substr($raw, 0, $ivLength),
            substr($raw, $ivLength, $tagLength)
        );

        return $plain === false ? null : $plain;
    }

    /**
     * 256-bit cookie key derived from the Slim secret; alerts in production when the secret is a default one
     *
     * @param Slim $slim
     *
     * @return string
     */
    protected function cookieKey(Slim $slim)
    {
        static $alerted = false;

        $secret = (string)$slim->config('cookies.secret_key');
        if (!$alerted && in_array($secret, self::COOKIE_DEFAULT_SECRETS, true)
            && environment::getCurrentEnvironment() === environment::ENV_PRODUCTION) {
            $alerted = true;
            self::log()->addAlert('Session cookie secret is the framework default: set `cookies.secret_key` in the slim config');
        }

        return hash('sha256', $secret, true);
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