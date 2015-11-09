<?php

namespace mpcmf\system\helper\communication;

use PHPMailer;

class mail
{
    /**
     * @var PHPMailer
     */
    private static $instance;

    /**
     * @return PHPMailer
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new PHPMailer();

            self::$instance->IsSendmail();
            self::$instance->IsHTML();
            self::$instance->CharSet = 'utf-8';
            self::$instance->ContentType = 'text/html';
            self::$instance->AddCustomHeader('Content-Type: text/html; charset="UTF-8"');
        }

        return self::$instance;
    }
}