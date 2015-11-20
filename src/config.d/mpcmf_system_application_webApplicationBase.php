<?php
/**
 * @author greevex
 * @date   : 11/16/12 5:11 PM
 */

\mpcmf\system\configuration\config::setConfig(__FILE__, [
    'name' => 'Web application base',
    'slim' => [
        // Debugging
        'debug' => true,
        // Logging
        'log.enabled' => true,
        // View
        'templates.path' => null,
        'view' => '\\mpcmf\\system\\view\\smartyDriver',
        // Cookies
        'cookies.encrypt' => true,
        'cookies.lifetime' => '60 minutes',
        'cookies.path' => '/',
        'cookies.domain' => null,
        'cookies.secure' => false,
        'cookies.httponly' => false,
        // Encryption
        'cookies.secret_key' => 'CHANGE_THIS_SECRET_KEY',
        'cookies.cipher' => MCRYPT_RIJNDAEL_256,
        'cookies.cipher_mode' => MCRYPT_MODE_CBC,
        // HTTP
        'http.version' => '1.1',
    ]
]);