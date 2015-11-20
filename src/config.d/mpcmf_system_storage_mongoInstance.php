<?php
/**
 * mongoInstance configuration
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */

use mpcmf\system\configuration\config;

config::setConfig(__FILE__, [
    'default' => [
        'uri' => 'mongodb://localhost',
        'options' => [
            'connect' => true,
        ]
    ],
    'mongo01' => [
        'uri' => 'mongodb://localhost',
        'options' => [
            'connect' => true,
        ]
    ],
    'localhost' => [
        'uri' => 'mongodb://localhost',
        'options' => [
            'connect' => true,
        ]
    ]
]);