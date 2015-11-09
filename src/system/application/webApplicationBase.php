<?php

namespace mpcmf\system\application;

use mpcmf\cache;
use mpcmf\loader;
use mpcmf\modules\moduleBase\actions\action;
use mpcmf\modules\moduleBase\exceptions\actionException;
use mpcmf\modules\moduleBase\exceptions\entityException;
use mpcmf\modules\moduleBase\exceptions\moduleException;
use mpcmf\modules\moduleBase\models\modelBase;
use mpcmf\modules\moduleBase\moduleBase;
use mpcmf\profiler;
use mpcmf\system\acl\aclManager;
use mpcmf\system\application\exception\applicationException;
use mpcmf\system\application\exception\webApplicationException;
use mpcmf\system\configuration\config;
use mpcmf\system\helper\i18n\i18n;
use mpcmf\system\helper\module\exception\modulePartsHelperException;
use mpcmf\system\helper\system\systemStructure;
use mpcmf\system\pattern\singleton;
use mpcmf\system\view\smartyDriver;
use Slim\Exception\Stop;
use Slim\Middleware\PrettyExceptions;
use Slim\Route;
use Slim\Slim;

/**
 * Web application based on Slim framework
 *
 * @docs http://docs.slimframework.com/
 * @author Ostrovsky Gregory <greevex@gmail.com>
 */
abstract class webApplicationBase
    extends applicationBase
{

    use singleton;

    const DEFAULT_TEMPLATES_DIRECTORY = '/system/modules/moduleBase/templates';

    const REQUEST__WITHOUT_BASE = '_wbase';
    const REQUEST__JSON = '_json';
    const REQUEST__JSON_PRETTY = '_jpretty';

    private $beforeBindingsProcessed = false;

    /**
     * @var Slim[]
     */
    private static $slimInstance;

    /**
     * Get application directory
     *
     * @return string
     * @throws webApplicationException
     */
    public function getApplicationDirectory()
    {
        static $directory = [];

        $appKey = get_called_class();
        if(!isset($directory[$appKey])) {
            $file = loader::getLoader()->findFile($appKey);
            if(!$file) {
                self::log()->addCritical("File for class {$appKey} not found!");
                throw new webApplicationException("File for class {$appKey} not found!");
            }
            $directory[$appKey] = dirname($file);
        }

        return $directory[$appKey];
    }

    /**
     * Get application namespace
     *
     * @return string
     */
    public function getApplicationNamespace()
    {
        static $namespace = [];

        $appKey = get_called_class();
        if(!isset($namespace[$appKey])) {
            $namespace[$appKey] = (new \ReflectionClass($appKey))->getNamespaceName();
            MPCMF_DEBUG && self::log()->addDebug("Web application namespace: {$namespace[$appKey]}");
        }

        return $namespace[$appKey];
    }

    /**
     * Load application modules
     *
     * @return moduleBase[]
     * @throws webApplicationException
     */
    public function getModules()
    {
        static $modules = [];

        $appKey = get_called_class();

        if(!isset($modules[$appKey])) {
            $cacheKey = 'webApp/getModules/' . md5($appKey);
            if(!($moduleDirs = cache::getCached($cacheKey))) {
                $moduleDirs = [];
                foreach (scandir($this->getApplicationDirectory() . '/modules') as $moduleName) {
                    if ($moduleName[0] === '.') {
                        continue;
                    }
                    MPCMF_DEBUG && self::log()->addDebug("Module found: {$moduleName}");
                    /** @var moduleBase $class */
                    $class = "\\mpcmf\\modules\\{$moduleName}\\module";
                    if (!class_exists($class)) {
                        MPCMF_DEBUG && self::log()->addDebug("Skipping module without base class: {$class}");
                        continue;
                    }
                    $moduleDirs[$moduleName] = $class;
                }

                cache::setCached($cacheKey, $moduleDirs);
            }

            $modules[$appKey] = [];
            foreach($moduleDirs as $moduleName => $moduleClass) {
                /** @var moduleBase $moduleClass */
                $modules[$appKey][$moduleName] = $moduleClass::getInstance();
            }
        }

        MPCMF_DEBUG && self::log()->addDebug('Modules loaded. Total: ' . count($modules[$appKey]));

        return $modules[$appKey];
    }

    /**
     * Load templates directories
     *
     * @return array
     * @throws webApplicationException
     */
    public function getTemplatesDirectories()
    {
        static $directories = [];

        $appKey = get_called_class();
        if(!isset($directories[$appKey])) {

            $cacheKey = 'webApp/tpldirs/' . md5($appKey);
            if(!($directories[$appKey] = cache::getCached($cacheKey))) {

                $directories[$appKey] = [];

                try {
                    foreach ($this->getAllModules() as $module) {
                        $templateDirectory = $module->getTemplatesDirectory();
                        MPCMF_DEBUG && self::log()->addDebug("Registering template directory: {$templateDirectory}");
                        $directories[$appKey][] = $templateDirectory;
                    }
                } catch (moduleException $moduleException) {
                    throw new webApplicationException("Load template directories exception: {$moduleException->getMessage()}", $moduleException->getCode(), $moduleException);
                }

                $defaultApplicationDirectory = $this->getApplicationDirectory() . '/templates';
                if (file_exists($defaultApplicationDirectory) && is_dir($defaultApplicationDirectory)) {
                    MPCMF_DEBUG && self::log()->addDebug("Registering application template directory: {$defaultApplicationDirectory}");
                    $directories[$appKey][] = $defaultApplicationDirectory;
                } else {
                    MPCMF_DEBUG && self::log()->addNotice("Application template directory not exists: {$defaultApplicationDirectory}");
                }

                $defaultSystemDirectory = APP_ROOT . self::DEFAULT_TEMPLATES_DIRECTORY;
                if (file_exists($defaultSystemDirectory) && is_dir($defaultSystemDirectory)) {
                    MPCMF_DEBUG && self::log()->addDebug("Registering system template directory: {$defaultSystemDirectory}");
                    $directories[$appKey][] = $defaultSystemDirectory;
                } else {
                    MPCMF_DEBUG && self::log()->addNotice("System template directory not exists: {$defaultSystemDirectory}");
                }

                MPCMF_DEBUG && self::log()->addDebug('Templates directories loaded. Total: ' . count($directories));

                cache::setCached($cacheKey, $directories[$appKey]);
            }
        }

        return $directories[$appKey];
    }

    /**
     * Do something before route bindings
     *
     * @throws webApplicationException
     */
    public function beforeBindings()
    {
        /** @var smartyDriver $view */
        $view = $this->slim()->view();

        $view->clearAllCache();

        if(!$this->beforeBindingsProcessed) {
            $this->beforeBindingsProcessed = true;
            if (!method_exists($view, 'addTemplatesDirectory')) {
                throw new webApplicationException('View driver doesn\'t implements `addTemplatesDirectory` method!');
            }
            try {
                foreach ($this->getTemplatesDirectories() as $templatesDirectory) {
                    $view->addTemplatesDirectory($templatesDirectory, md5($templatesDirectory));
                }
            } catch (\InvalidArgumentException $invalidArgumentException) {
                throw new webApplicationException("View driver error: {$invalidArgumentException->getMessage()}", $invalidArgumentException->getCode(), $invalidArgumentException);
            } catch (applicationException $applicationException) {
                throw new webApplicationException("View driver error: {$applicationException->getMessage()}", $applicationException->getCode(), $applicationException);
            }
        }
    }

    /**
     * Do something after binding but before application starts
     *
     * @return void
     */
    abstract protected function beforeApplication();

    /**
     * Do something after application ends
     *
     * @return void
     */
    abstract protected function afterApplication();

    /**
     * Run web application
     *
     * @param bool $processBindings
     *
     * @throws webApplicationException
     * @throws Stop
     */
    public function run($processBindings = true)
    {
        profiler::addStack('app::run');

        MPCMF_DEBUG && self::log()->addDebug('Before bindings call...');
        $this->beforeBindings();
        if($processBindings) {
            MPCMF_DEBUG && self::log()->addDebug('Processing bindings...');
            $this->processBindings();
        }
        MPCMF_DEBUG && self::log()->addDebug('Before web application call...');
        $this->beforeApplication();
        MPCMF_DEBUG && self::log()->addDebug('Web application starts...');

        $this->slim()->run();

        MPCMF_DEBUG && self::log()->addDebug('Web application ends...');
        $this->afterApplication();
    }

    /**
     * Load routes for web application
     *
     * @return array
     *
     * @throws webApplicationException
     * @throws Stop
     */
    public function processBindings()
    {
        foreach ($this->getRoutes() as $routeName => $routerParams) {
            $this->bindRoute($routeName, $routerParams);
        }
    }

    /**
     * @return array
     */
    public function getRoutes()
    {
        $cacheKey = "webApp/routes/{$this->getApplicationName()}";
        if (!($routes = cache::getCached($cacheKey))) {
            $routes = [];
            foreach ($this->getAllModules() as $module) {
                foreach ($module->getModuleRoutes()->getRoutes() as $routeName => $routeParams) {
                    MPCMF_DEBUG && self::log()->addDebug("Binding route `{$routeName}`");
                    $routes[$routeName] = $routeParams;
                }
            }

            cache::setCached($cacheKey, $routes);
        }

        return $routes;
    }

    /**
     * @return \mpcmf\modules\moduleBase\moduleBase[]
     */
    public function getAllModules()
    {
        /** @var moduleBase[][] $sortedModules */
        /** @var moduleBase[] $allModules */
        static $sortedModules = [], $allModules;

        $appKey = get_called_class();
        if (!isset($sortedModules[$appKey])) {

            if ($allModules === null) {
                $allModules = systemStructure::getInstance()->getModuleInstances();
            }

            $sortedModules[$appKey] = $this->getModules();
            foreach($allModules as $moduleName => $moduleInstance) {
                if (!isset($sortedModules[$appKey][$moduleName])) {
                    $sortedModules[$appKey][$moduleName] = $moduleInstance;
                }
            }
        }

        return $sortedModules[$appKey];
    }

    /**
     * Bind web application routes
     *
     * @param $routeName
     * @param $routeParams
     *
     * @throws webApplicationException
     * @throws Stop
     */
    protected function bindRoute($routeName, $routeParams)
    {
        /** @var Slim[] $slim */
        static $slim = [];

        $appKey = get_called_class();

        profiler::addStack('app::bind');

        if(!isset($slim[$appKey])) {
            $slim[$appKey] = $this->slim();
        }

        $route = $routeParams['route'];

        /** @var action $action */
        $action = $routeParams['action'];

        MPCMF_DEBUG && self::log()->addDebug("Mapping route `{$route}` to " . $action->getTemplate());

        $route = $slim[$appKey]->map("{$route}", function() use ($action) {
            $this->dispatch($action, func_get_args());
        })->via($action->getHttp());
        MPCMF_DEBUG && self::log()->addDebug('Route is accessible via ' . implode(', ', $action->getHttp()));
        $route->conditions($action->getRequired());
        $route->setName($routeName);
    }

    /**
     * @param      $actionName
     * @param array $arguments
     * @param null $withoutBase
     * @param bool $stopApp
     *
     * @throws Stop
     * @throws webApplicationException
     */
    public function dispatchByName($actionName, array $arguments = [], $withoutBase = null, $stopApp = true)
    {
        static $routes;

        $appKey = get_called_class();
        if(!isset($routes[$appKey])) {
            $routes[$appKey] = $this->getRoutes();
        }
        if(!isset($routes[$appKey][$actionName])) {
            throw new webApplicationException("Unable to find route {$actionName} to render");
        }

        $this->dispatch($routes[$appKey][$actionName]['action'], $arguments, $withoutBase, $stopApp);
    }

    /**
     * @param action    $action
     * @param array     $arguments
     * @param null|bool $withoutBase
     *
     * @param bool      $stopApp
     *
     * @throws Stop
     * @throws webApplicationException
     */
    public function dispatch(action $action, array $arguments = [], $withoutBase = null, $stopApp = false)
    {
        static $apiTypes;

        profiler::addStack('app::dispatch');

        if($apiTypes === null) {
            $apiTypes = [
                action::TYPE__API_FOR_ITEM => true,
                action::TYPE__API_GLOBAL => true,
            ];
        }

        $slim = Slim::getInstance();

        $aclManager = aclManager::getInstance();
        $request = $slim->request();
        $isApiRequest = isset($apiTypes[$action->getType()]);

        $isJson = $request->get(self::REQUEST__JSON, false) || $isApiRequest;
        $jsonPretty = $request->get(self::REQUEST__JSON_PRETTY, false);
        $jsonTemplate = $jsonPretty ? 'json.pretty.tpl' : 'json.tpl';

        if($isApiRequest) {
            $aclResponse = $aclManager->checkActionAccessByToken($action, $request->get('access_token'));
            if(!$aclResponse['status']) {
                $slim->render($jsonTemplate, [
                    'data' => $aclResponse
                ]);
                $slim->stop();
            }
        } else {
            $aclManagerResponse = $aclManager->checkActionAccess($action);
            if (!$aclManagerResponse['status']) {
                $currentUrl = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
                $forbiddenUrl = $this->getUrl('/authex/user/forbidden', [
                    'redirectUrl' => base64_encode($currentUrl)
                ]);
                $slim->redirect($forbiddenUrl);
                $slim->stop();
            }
        }

        try {
            $result = call_user_func_array($action->getCallable(), $arguments);
        } catch(actionException $actionException) {
            throw new webApplicationException("Action exception: {$actionException->getMessage()}", $actionException->getCode(), $actionException);
        }

        if($isApiRequest) {
            $slim->render($jsonTemplate, ['data' => $result]);
            $slim->stop();
        } elseif($isJson) {
            array_walk_recursive($result, function(&$item) {
                if($item instanceof \Traversable) {
                    $item = iterator_to_array($item);
                    foreach($item as &$itemValue) {
                        if($itemValue instanceof modelBase) {
                            $itemValue = $itemValue->export();
                        } else {
                            break;
                        }
                    }
                } elseif($item instanceof modelBase) {
                    $item = $item->export();
                }
            });
            $slim->render($jsonTemplate, ['data' => $result]);
        } else {
            try {
                $result['_entity'] = $action->getEntityActions()->getEntity();
                $result['i18n'] = i18n::lang();
                $result['_profiler'] = profiler::get();
                $result['_acl'] = $aclManager;
                $result['_slim'] = $slim;
                $result['_route'] = $slim->router()->getCurrentRoute();
                $result['_application'] = $this;
            } catch(entityException $entityException) {
                throw new webApplicationException("Web application route process exception: {$entityException->getMessage()}", $entityException->getCode(), $entityException);
            } catch(modulePartsHelperException $modulePartsHelperException) {
                throw new webApplicationException("Web application route process exception: {$modulePartsHelperException->getMessage()}", $modulePartsHelperException->getCode(), $modulePartsHelperException);
            }
            if($withoutBase === null) {
                $withoutBase = (bool)$request->get(self::REQUEST__WITHOUT_BASE, false);
            }
            try {
                if (!$withoutBase && $action->useBase()) {
                    $result['_template'] = $action->getTemplate();
                    $slim->render('index.tpl', $result);
                } else {
                    $result['_template'] = '';
                    $slim->render($action->getTemplate(), $result);
                }
            } catch(Stop $stopException) {
                if(!$stopApp) {
                    throw $stopException;
                }
            }
        }
    }

    public function getUrl($routeName, array $params = [])
    {
        $router = $this->slim()->router();
        $routePattern = $router->getNamedRoute($routeName)->getPattern();
        preg_match_all('/\:(?<params>\w+)/', $routePattern, $routeParams);

        $queryParams = [];
        $baseParams = [];
        foreach ($params as $paramKey => $paramValue) {
            if (in_array($paramKey, $routeParams['params'], true)) {
                $baseParams[$paramKey] = $paramValue;

                continue;
            }
            $queryParams[$paramKey] = $paramValue;
        }

        return $router->urlFor($routeName, $baseParams) . (count($queryParams) > 0 ? ('?' . http_build_query($queryParams)) : '');
    }

    public function render($path, $params, $method = 'GET')
    {
        profiler::addStack('app::render');

        MPCMF_DEBUG && self::log()->addDebug('Rendering: ' . json_encode(func_get_args()));
        $matchedRoutes = $this->slim()->router()->getMatchedRoutes($method, $path . ($params ? ('?' . http_build_query($params)) : ''), true);
        /** @var Route $route */
        $route = reset($matchedRoutes);
        return $route->dispatch();
    }

    /**
     * Get micro-web-framework object
     *
     * @param null $instance
     * @param string|null $appKey
     *
     * @return Slim
     * @throws webApplicationException
     */
    public function slim($instance = null, $appKey = null)
    {
        if($appKey === null) {
            $appKey = get_called_class();
        }

        if($instance !== null) {
            self::$slimInstance[$appKey] = $instance;
        } elseif(!isset(self::$slimInstance[$appKey])) {
            $config = array_replace_recursive(config::getConfig(__CLASS__), $this->getPackageConfig());

            if(!isset($config['slim'], $config['name'])) {
                throw new webApplicationException('Undefined required config sections: slim, name');
            }

            self::$slimInstance[$appKey] = new Slim($config['slim']);
            self::$slimInstance[$appKey]->setName($appKey);

            set_error_handler(array('\Slim\Slim', 'handleErrors'));

            //Apply final outer middleware layers
            if (self::$slimInstance[$appKey]->config('debug')) {
                //Apply pretty exceptions only in debug to avoid accidental information leakage in production
                self::$slimInstance[$appKey]->add(new PrettyExceptions());
            }
        }

        return self::$slimInstance[$appKey];
    }

    public function getMenuStructure($full = false, $reallyFull = false)
    {
        $aclManager = aclManager::getInstance();

        $groups = $aclManager->getCurrentUser()->getGroupIds();
        $cacheKey = 'webApp/sidebar/menu/' . md5(json_encode($groups));

        if(!($menu = cache::getCached($cacheKey))) {
            $homeMenuItem = [
                'path' => '/',
                'name' => i18n::lang()->get('Главная'),
            ];

            $menu = [
                $homeMenuItem
            ];

            foreach ($this->getAllModules() as $moduleName => $module) {
                $modulePath = "/{$moduleName}";
                $menuItem = [
                    'path' => $modulePath,
                    'name' => $module->getName(),
                    'subitems' => []
                ];

                $hasSubItemsAccess = false;

                /**
                 * @var string $entityUniqueName
                 * @var array  $entity
                 */
                foreach ($module->getModuleRoutes()->getStructure() as $entityUniqueName => $entityData) {
                    /** @var modelBase $entityModel */
                    $entityModel = $entityData['entity'];
                    $subMenuItem = [
                        'path' => $entityUniqueName,
                        'name' => $entityModel->getPublicName(),
                        'subitems' => []
                    ];

                    $hasSubSubItemsAccess = false;

                    /**
                     * @var string $actionName
                     * @var action $actionModel
                     */
                    foreach ($entityData['actions'] as $actionName => $actionModel) {
                        if (!$reallyFull && $actionModel->getType() !== action::TYPE__GLOBAL) {
                            continue;
                        }

                        $actionPath = preg_replace('/\(.*\)/', '', $actionModel->getPath());
                        if ($actionModel->isRelative()) {
                            $path = $actionModel->getActionUniqueName() . $actionPath;
                        } else {
                            $path = '/' . ltrim($actionPath, '/');
                        }

                        $hasAccess = $aclManager->checkActionAccess($actionModel)['status'];

                        if (!$hasSubSubItemsAccess && $hasAccess) {
                            $hasSubSubItemsAccess = true;
                        }

                        if ($full || $reallyFull || $hasAccess) {

                            $subSubMenuItem = [
                                'path' => $path,
                                'name' => $actionModel->getName(),
                                'access' => $hasAccess,
                            ];

                            $subMenuItem['subitems'][] = $subSubMenuItem;
                        }
                    }

                    $subMenuItem['hasSubItemsAccess'] = $hasSubSubItemsAccess;

                    if (!$hasSubItemsAccess && $hasSubSubItemsAccess) {
                        $hasSubItemsAccess = true;
                    }

                    if ($full || $reallyFull || $hasSubSubItemsAccess) {
                        $menuItem['subitems'][] = $subMenuItem;
                    }
                }

                $menuItem['hasSubItemsAccess'] = $hasSubItemsAccess;

                if ($full || $reallyFull || $hasSubItemsAccess) {
                    $menu[] = $menuItem;
                }
            }

            cache::setCached($cacheKey, $menu, 300);
        }

        $slim = $this->slim();
        $currentRoutePath = $slim->urlFor($slim->router()->getCurrentRoute()->getName());

        $this->processMenuItems($menu, $currentRoutePath);

        return $menu;
    }

    protected function processMenuItems(&$menu, $currentRoutePath)
    {
        foreach($menu as &$subItem) {
            $subItem['active'] = $this->checkIsMenuItemActive($subItem, $currentRoutePath);
            if($subItem['active'] && !empty($subItem['subitems'])) {
                $this->processMenuItems($subItem['subitems'], $currentRoutePath);
            }
        }
    }

    protected function checkIsMenuItemActive($item, $currentPath)
    {
        if(($currentPath === '/' || $currentPath === '') && $item['path'] === '/') {
            return true;
        } elseif($currentPath !== '/' && $currentPath !== '') {
            return strpos($currentPath, $item['path']) !== false;
        } else {
            return false;
        }
    }
}