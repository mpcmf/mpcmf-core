<?php

namespace mpcmf\modules\moduleBase\actions;

use mpcmf\modules\moduleBase\exceptions\actionException;
use mpcmf\modules\moduleBase\exceptions\controllerException;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\system\helper\io\log;

/**
 * Base action abstraction class
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 * @date: 2/27/15 1:41 PM
 */
class action
{
    use log;

    const TYPE__DEFAULT = 0;
    const TYPE__GLOBAL = 1;
    const TYPE__FOR_ITEM = 2;
    const TYPE__API_GLOBAL = 3;
    const TYPE__API_FOR_ITEM = 4;
    const TYPE__API_FREE_ACCESS = 5;

    /**
     * @var string
     */
    private $entityControllerClassName;

    /**
     * @var actionsBase
     */
    private $entityActionsInstance;

    /**
     * @var array
     */
    private $actionData = [
        'name' => null,
        'method' => null,
        'callable' => null,
        'http' => [],
        'required' => [],
        'useBase' => true,
        'template' => null,
        'path' => null,
        'relative' => true,
        'type' => self::TYPE__DEFAULT,
        'acl' => [],
    ];

    /**
     * Instantiate new action
     *
     * @param             $actionData
     * @param actionsBase $entityActions
     *
     * @throws actionException
     */
    public function __construct($actionData, actionsBase $entityActions)
    {
        $this->entityActionsInstance = $entityActions;

        try {
            $this->entityControllerClassName = $this->entityActionsInstance->getController()->getCurrentClassName();
        } catch(modulePartsHelperException $controllerException) {
            throw new actionException("Unable to call controller class name: {$controllerException->getMessage()}", $controllerException->getCode(), $controllerException);
        } catch(controllerException $controllerException) {
            throw new actionException("Controller exception: {$controllerException->getMessage()}", $controllerException->getCode(), $controllerException);
        }

        $this->setActionData($actionData);
    }

    /**
     * Add data to action by array
     *
     * @param $actionData
     *
     * @throws actionException
     */
    private function setActionData($actionData)
    {
        $actionData['callable'] = [
            $this->entityControllerClassName,
            $actionData['method']
        ];

        if(!is_callable($actionData['callable'])) {
            throw new actionException('Method for action doesn\'t exists: ' . json_encode($actionData));
        }
        $callable = $actionData['callable'];
        $reflection = new \ReflectionMethod($callable[0], $callable[1]);
        if(!is_object($callable[0]) && !$reflection->isStatic()) {
            if(!method_exists($callable[0], 'getInstance')) {
                $callable[0] = new $callable[0]();
            } else {
                $callable[0] = $callable[0]::getInstance();
            }
        }
        $actionData['callable'] = $callable;
        if(empty($actionData['path'])) {
            $actionData['relative'] = true;
        }

        if(!isset($actionData['acl']) || !is_array($actionData['acl'])) {
            $actionData['acl'] = [];
        }

        $this->actionData = array_replace($this->actionData, $actionData);
    }

    /**
     * Get relation to entityActions object
     *
     * @return actionsBase
     */
    public function getEntityActions()
    {
        return $this->entityActionsInstance;
    }

    /**
     * Get action name
     *
     * @return string
     */
    public function getName()
    {
        return $this->actionData['name'];
    }

    /**
     * Get action callable method name
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->actionData['method'];
    }

    /**
     * Get action valid callable
     *
     * @return callable
     * @throws actionException
     */
    public function getCallable()
    {
        if(!isset($this->actionData['callable'])) {
            $this->actionData['callable'] = [
                $this->entityControllerClassName,
                $this->actionData['method']
            ];

            if(!is_callable($this->actionData['callable'])) {
                throw new actionException('Method for action doesn\'t exists: ' . json_encode($this->actionData));
            }
        }
        return $this->actionData['callable'];
    }

    /**
     * Get http methods
     *
     * @return array
     */
    public function getHttp()
    {
        return $this->actionData['http'];
    }

    /**
     * Get required fields and theirs regex conditions
     *
     * @return array
     */
    public function getRequired()
    {
        return $this->actionData['required'];
    }

    /**
     * Use base template to render this action's template
     *
     * @return mixed
     */
    public function useBase()
    {
        return $this->actionData['useBase'];
    }

    /**
     * Get route path for this action
     *
     * @return string
     */
    public function getPath()
    {
        return $this->actionData['path'];
    }

    /**
     * Is route path relative
     *
     * @return bool
     */
    public function isRelative()
    {
        return (bool)$this->actionData['relative'];
    }

    /**
     * Get action template filepath
     *
     * @return mixed
     */
    public function getTemplate()
    {
        return $this->actionData['template'];
    }

    /**
     * Get action type
     *
     * @return mixed
     */
    public function getType()
    {
        return $this->actionData['type'];
    }

    /**
     * Get full ACL of current action
     *
     * @return array
     */
    public function getAcl()
    {
        return $this->actionData['acl'];
    }

    /**
     * Get action data as array
     *
     * @return array
     */
    public function getActionData()
    {
        return $this->actionData;
    }

    public function getActionUniqueName()
    {
        return $this->getEntityActions()->getEntityUniqueName() . '/' . $this->getEntityActions()->findAction($this);
    }

    public function getAclGroupName()
    {
        return ltrim($this->getActionUniqueName(), '/');
    }
}