<?php

namespace mpcmf\system\helper\module;

/**
 * Module parts static storage class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * */
class modulePartsStorage
{
    public static $entityTypes = [
        'actions' => 'Actions',
        'controllers' => 'Controller',
        'entities' => 'Entity',
        'models' => 'Model',
        'mappers' => 'Mapper'
    ];

    public static $namespaces = [];
    public static $directories = [];
    public static $entityNames = [];
    public static $entityPublicNames = [];
    public static $entityInstances = [];
    public static $entityActionsInstances = [];
    public static $entityMappers = [];
    public static $entityModels = [];
    public static $entityControllers = [];
    public static $moduleInstances = [];
    public static $moduleNames = [];
    public static $moduleNamespaces = [];
}
