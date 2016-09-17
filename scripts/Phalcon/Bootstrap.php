<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Developer Tools                                                |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2016 Phalcon Team (http://www.phalconphp.com)       |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file docs/LICENSE.txt.                        |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  |          Serghei Iakovlev <serghei@phalconphp.com>                     |
  +------------------------------------------------------------------------+
*/

namespace Phalcon;

use Phalcon\Mvc\View;
use DirectoryIterator;
use Phalcon\Events\Event;
use Phalcon\Utils\FsUtils;
use Phalcon\Db\Adapter\Pdo;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\AdapterInterface;
use Phalcon\Mvc\View\Engine\Php;
use Phalcon\Logger\Adapter\Stream;
use Phalcon\Mvc\Url as UrlResolver;
use Phalcon\Resources\AssetsResource;
use Phalcon\Mvc\View\NotFoundListener;
use Phalcon\Elements\Menu\SidebarMenu;
use Phalcon\Flash\Direct as FlashDirect;
use Phalcon\Access\Policy\Ip as IpPolicy;
use Phalcon\Flash\Session as FlashSession;
use Phalcon\Mvc\Dispatcher as MvcDispatcher;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Assets\Manager as AssetsManager;
use Phalcon\Config\Adapter\Ini as IniConfig;
use Phalcon\Access\Manager as AccessManager;
use Phalcon\Session\Adapter\Files as Session;
use Phalcon\Logger\Adapter\File as FileLogger;
use Phalcon\Mvc\Application as MvcApplication;
use Phalcon\Config\Adapter\Yaml as YamlConfig;
use Phalcon\Config\Adapter\Json as JsonConfig;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Application as AbstractApplication;
use Phalcon\Cache\Frontend\None as FrontendNone;
use Phalcon\Cache\Backend\Memory as BackendCache;
use Phalcon\Cache\Frontend\Output as FrontOutput;
use Phalcon\Logger\Formatter\Line as LineFormatter;
use Phalcon\Mvc\Router\Annotations as AnnotationsRouter;
use Phalcon\Mvc\View\Engine\Volt\Extension\Php as PhpExt;
use Phalcon\Annotations\Adapter\Memory as AnnotationsMemory;
use Phalcon\Mvc\Dispatcher\ErrorHandler as DispatchErrorHandler;

/**
 * \Phalcon\Web\Tools\Library\Bootstrap
 *
 * @package Phalcon\Web\Tools\Library
 */
class Bootstrap
{
    /**
     * Application instance.
     * @var AbstractApplication
     */
    private $app;

    /**
     * The services container.
     * @var FactoryDefault
     */
    private $di;

    /**
     * The path to the Phalcon Developers Tools.
     * @var string
     */
    private $ptoolsPath = '';

    /**
     * The allowed IP for access.
     * @var string
     */
    private $ptoolsIp = '';

    /**
     * The path where the project was created.
     * @var string
     */
    private $basePath = '';

    /**
     * The current hostname.
     * @var string
     */
    private $hostName = 'Unknown';

    /**
     * The current application mode.
     * @var string
     */
    private $mode = 'web';

    private $configurable = [
        'ptools_path',
        'ptools_ip',
        'base_path',
        'hostName',
    ];

    private $loaders = [
        'web' => [
            'eventsManager',
            'config',
            'logger',
            'cache',
            'volt',
            'view',
            'annotations',
            'router',
            'url',
            'tag',
            'dispatcher',
            'assets',
            'session',
            'flash',
            'database',
            'accessManager',
            'registry',
            'utils',
            'ui',
        ],
    ];

    /**
     * Bootstrap constructor.
     *
     * @param array $parameters
     */
    public function __construct(array $parameters = [])
    {
        $defines = [
            'PTOOLSPATH' => 'ptoolsPath',
            'PTOOLS_IP'  => 'ptoolsIp',
            'BASE_PATH'  => 'basePath',
            'HOSTNAME'   => 'hostName',
        ];

        foreach ($defines as $const => $property) {
            if (defined($const)) {
                $this->{$property} = rtrim(trim(constant($const)), '\\/');
            }
        }

        $this->setParams($parameters);

        $this->di  = new FactoryDefault;
        $this->app = new MvcApplication;

        foreach ($this->loaders[$this->mode] as $service) {
            $serviceName = ucfirst($service);
            $this->{'init' . $serviceName}();
        }

        $this->app->setEventsManager($this->di->getShared('eventsManager'));

        $this->di->setShared('application', $this->app);
        $this->app->setDI($this->di);

        Di::setDefault($this->di);
    }

    /**
     * Runs the Application.
     *
     * @return AbstractApplication|string
     */
    public function run()
    {
        if (ENV_TESTING === APPLICATION_ENV) {
            return $this->app;
        }

        return $this->getOutput();
    }

    /**
     * Get application output.
     *
     * @return string
     */
    public function getOutput()
    {
        return $this->app->handle()->getContent();
    }

    /**
     * Set the WebTools params.
     *
     * @param array $params
     *
     * @return $this
     */
    public function setParams(array $params)
    {
        foreach ($this->configurable as $param) {
            if (!isset($params[$param])) {
                continue;
            }

            $method = 'set' . Text::camelize($param);

            if (method_exists($this, $method)) {
                $this->$method($params[$param]);
            }
        }

        return $this;
    }

    /**
     * Sets the path to the Phalcon Developers Tools.
     *
     * @todo Use Path::normalize()
     * @param string $path
     *
     * @return $this
     */
    public function setPtoolsPath($path)
    {
        $this->ptoolsPath = rtrim($path, '\\/');

        return $this;
    }

    /**
     * Gets the path to the Phalcon Developers Tools.
     *
     * @return string
     */
    public function getPtoolsPath()
    {
        return $this->ptoolsPath;
    }

    /**
     * Sets the allowed IP for access.
     *
     * @param string $ip
     *
     * @return $this
     */
    public function setPtoolsIp($ip)
    {
        $this->ptoolsIp = trim($ip);

        return $this;
    }

    /**
     * Gets the allowed IP for access.
     *
     * @return string
     */
    public function getPtoolsIp()
    {
        return $this->ptoolsIp;
    }

    /**
     * Sets the path where the project was created.
     *
     * @todo Use Path::normalize()
     * @param string $path
     *
     * @return $this
     */
    public function setBasePath($path)
    {
        $this->basePath = rtrim($path, '\\/');

        return $this;
    }

    /**
     * Gets the path where the project was created.
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * Sets the current application mode.
     *
     * @param string $mode
     *
     * @return $this
     */
    public function setMode($mode)
    {
        $mode = strtolower(trim($mode));

        if (isset($this->loaders[$mode])) {
            $mode = 'web'; // @todo
        }

        $this->mode = $mode;

        return $this;
    }

    /**
     * Gets the current application mode.
     *
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * Sets the current hostname.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setHostName($name)
    {
        $this->hostName = trim($name);

        return $this;
    }

    /**
     * Gets the current application mode.
     *
     * @return string
     */
    public function getHostName()
    {
        return $this->hostName;
    }

    /**
     * Initialize the Application Events Manager.
     */
    protected function initEventsManager()
    {
        $this->di->setShared(
            'eventsManager',
            function () {
                $em = new EventsManager;
                $em->enablePriorities(true);

                return $em;
            }
        );
    }

    /**
     * Initialize the Application Config.
     */
    protected function initConfig()
    {
        $path = $this->basePath;

        $this->di->setShared(
            'config',
            function () use ($path) {
                /** @var DiInterface $this */

                $configDirs = [
                    'config',
                    'app/config',
                    'apps/config',
                    'app/frontend/config',
                    'apps/frontend/config',
                    'app/backend/config',
                    'apps/backend/config',
                ];

                $configAdapters = [
                    'ini'  => IniConfig::class,
                    'json' => JsonConfig::class,
                    'php'  => Config::class,
                    'php5' => Config::class,
                    'inc'  => Config::class,
                    'yml'  => YamlConfig::class,
                    'yaml' => YamlConfig::class,
                ];

                $config = null;

                // @todo Add scan for dev config
                foreach ($configDirs as $configPath) {
                    $probablyPath = $path . DS . str_replace('/', DS, $configPath);

                    foreach ($configAdapters as $ext => $adapter) {
                        $probablyConfig = $probablyPath . DS . 'config.' . $ext;

                        if (is_file($probablyConfig) && is_readable($probablyConfig)) {
                            if (in_array($ext, ['php', 'php5', 'inc'])) {
                                /** @noinspection PhpIncludeInspection */
                                $config = include($probablyConfig);
                                if (is_array($config)) {
                                    $config = new Config($config);
                                }
                            } else {
                                $config = new $adapter($probablyConfig);
                            }

                            $this->getShared('logger')->debug('Found config at path: {path}', [
                                'path' => $probablyConfig,
                            ]);
                            break(2);
                        }
                    }
                }

                if (null === $config) {
                    // @todo Use Config Exception here
                    trigger_error(
                        sprintf(
                            "Configuration file couldn't be loaded! Scanned dirs: %s",
                            join(', ', array_map(function ($val) use ($path) {
                                return $path . DS . str_replace('/', DS, $val);
                            }, $configDirs))
                        ),
                        E_USER_ERROR
                    );

                    exit(1);
                }

                if (!$config instanceof Config) {
                    $type = gettype($config);
                    if ($type == 'boolean') {
                        $type .= ($type ? ' (true)' : ' (false)');
                    } elseif (is_object($type)) {
                        $type = get_class($type);
                    }

                    // @todo Use Config Exception here
                    trigger_error(
                        sprintf(
                            'Unable to read config file. Config must be either an array or %s instance. Got %s',
                            Config::class,
                            $type
                        ),
                        E_USER_ERROR
                    );

                    exit(1);
                }

                return $config;
            }
        );
    }

    /**
     * Initialize the Logger.
     */
    protected function initLogger()
    {
        $hostName = $this->hostName;
        $basePath = $this->basePath;

        $this->di->setShared(
            'logger',
            function () use ($hostName, $basePath) {
                $logLevel = Logger::ERROR;
                if (ENV_DEVELOPMENT === APPLICATION_ENV) {
                    $logLevel = Logger::DEBUG;
                }

                $ptoolsPath = $basePath . DS . '.phalcon' . DS;
                if (is_dir($ptoolsPath) && is_writable($ptoolsPath)) {
                    $formatter = new LineFormatter("%date% {$hostName} php: [%type%] %message%", 'D j H:i:s');
                    $logger    = new FileLogger($ptoolsPath . 'devtools.log');
                } else {
                    $formatter = new LineFormatter("[devtools@{$hostName}]: [%type%] %message%", 'D j H:i:s');
                    $logger    = new Stream('php://stderr');
                }

                $logger->setFormatter($formatter);
                $logger->setLogLevel($logLevel);

                return $logger;
            }
        );
    }

    /**
     * Initialize the Cache.
     *
     * The frontend must always be Phalcon\Cache\Frontend\Output and the service 'viewCache'
     * must be registered as always open (not shared) in the services container (DI).
     */
    protected function initCache()
    {
        $this->di->set(
            'viewCache',
            function () {
                return new BackendCache(new FrontOutput);
            }
        );

        $this->di->setShared(
            'modelsCache',
            function () {
                return new BackendCache(new FrontendNone);
            }
        );

        $this->di->setShared(
            'dataCache',
            function () {
                return new BackendCache(new FrontendNone);
            }
        );
    }

    /**
     * Initialize the Volt Template Engine.
     */
    protected function initVolt()
    {
        $basePath = $this->basePath;
        $ptoolsPath = $this->ptoolsPath;

        $this->di->setShared(
            'volt',
            function ($view, $di) use ($basePath, $ptoolsPath) {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 * @var Config $voltConfig
                 */

                $volt = new VoltEngine($view, $di);
                $config = $this->getShared('config');

                $appCacheDir = $config->get('application', new Config)->get('cacheDir');
                $defaultCacheDir = sys_get_temp_dir() . DS . 'phalcon' . DS . 'volt';

                $voltConfig = null;
                if ($config->offsetExists('volt')) {
                    $voltConfig = $config->get('volt');
                } elseif ($config->offsetExists('view')) {
                    $voltConfig = $config->get('view');
                }

                if (!$voltConfig instanceof Config) {
                    $voltConfig = new Config([
                        'compiledExt'  => '.php',
                        'separator'    => '_',
                        'cacheDir'     => $appCacheDir ?: $defaultCacheDir,
                        'forceCompile' => ENV_DEVELOPMENT === APPLICATION_ENV,
                    ]);
                }

                $compiledPath = function ($templatePath) use (
                    $voltConfig,
                    $basePath,
                    $ptoolsPath,
                    $appCacheDir,
                    $defaultCacheDir
                ) {
                    /**
                     * @var DiInterface $this
                     * @var Config $voltConfig
                     */

                    if (0 === strpos($templatePath, $basePath)) {
                        $templatePath = substr($templatePath, strlen($basePath));
                    } elseif (0 === strpos($templatePath, $ptoolsPath . DS . 'scripts')) {
                        $templatePath = substr($templatePath, strlen($ptoolsPath . DS . 'scripts'));
                    }

                    $filename = str_replace(['\\', '/'], $voltConfig->get('separator', '_'), trim($templatePath, '\\/'));
                    $filename = basename($filename, '.volt') . $voltConfig->get('compiledExt', '.php');
                    $cacheDir = $voltConfig->get('cacheDir', $appCacheDir);

                    if (!$cacheDir || !is_dir($cacheDir) || !is_writable($cacheDir)) {
                        $this->getShared('logger')->warning(
                            'Unable to initialize Volt cache dir: {cache}. Used temp path: {default}',
                            [
                                'cache'   => $cacheDir,
                                'default' => $defaultCacheDir
                            ]
                        );

                        $cacheDir = $defaultCacheDir;
                        mkdir($cacheDir, 0777, true);
                    }

                    return rtrim($cacheDir, '\\/') . DS . $filename;
                };

                $options = [
                    'compiledPath'  => $voltConfig->get('compiledPath', $compiledPath),
                    'compileAlways' => ENV_DEVELOPMENT === APPLICATION_ENV || boolval($voltConfig->get('forceCompile')),
                ];

                $volt->setOptions($options);
                $volt->getCompiler()->addExtension(new PhpExt);

                return $volt;
            }
        );
    }

    /**
     * Initialize the View.
     */
    protected function initView()
    {
        $this->di->setShared(
            'view',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Registry $registry
                 */

                $view = new View;
                $registry = $this->getShared('registry');

                $view->registerEngines(
                    [
                        '.volt'  => $this->getShared('volt', [$view, $this]),
                        '.phtml' => Php::class
                    ]
                );

                $view->setViewsDir($registry->offsetGet('directories')->webToolsViews . DS)
                    ->setLayoutsDir('layouts' . DS)
                    ->setRenderLevel(View::LEVEL_AFTER_TEMPLATE);

                $em = $this->getShared('eventsManager');
                $em->attach('view', new NotFoundListener);

                $view->setEventsManager($em);

                return $view;
            }
        );
    }

    /**
     * Initialize the Annotations.
     */
    protected function initAnnotations()
    {
        $this->di->setShared(
            'annotations',
            function () {
                return new AnnotationsMemory;
            }
        );
    }

    /**
     * Initialize the Router.
     */
    protected function initRouter()
    {
        $ptoolsPath = $this->ptoolsPath;

        $this->di->setShared(
            'router',
            function () use ($ptoolsPath) {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                $router = new AnnotationsRouter(false);
                $router->removeExtraSlashes(true);

                // @todo Use Path::normalize()
                $controllersDir = $ptoolsPath . DS . str_replace('/', DS, 'scripts/Phalcon/Web/Tools/Controllers');
                $dir = new DirectoryIterator($controllersDir);

                $resources = [];

                foreach ($dir as $fileInfo) {
                    if ($fileInfo->isDot() || false === strpos($fileInfo->getBasename(), 'Controller.php')) {
                        continue;
                    }

                    $controller = $fileInfo->getBasename('Controller.php');
                    $resources[] = $controller;
                }

                foreach ($resources as $controller) {
                    $router->addResource($controller);
                }

                $router->setEventsManager($em);
                $router->setDefaultAction('index');
                $router->setDefaultController('index');
                $router->setDefaultNamespace('WebTools\Controllers');
                $router->notFound(['controller' => 'error', 'action' => 'route404']);

                return $router;
            }
        );
    }

    /**
     * Initialize the Url service.
     */
    protected function initUrl()
    {
        $this->di->setShared(
            'url',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 */
                $config = $this->getShared('config');

                $url = new UrlResolver;

                if ($config->get('application', new Config)->offsetExists('baseUri')) {
                    $baseUri = $config->get('application', new Config)->get('baseUri');
                } elseif ($config->offsetExists('baseUri')) {
                    $baseUri = $config->get('baseUri');
                } else {
                    // @todo Log notice here
                    $baseUri = '/';
                }

                if ($config->get('application', new Config)->offsetExists('staticUri')) {
                    $staticUri = $config->get('application', new Config)->get('staticUri');
                } elseif ($config->offsetExists('staticUri')) {
                    $staticUri = $config->get('staticUri');
                } else {
                    // @todo Log notice here
                    $staticUri = '/';
                }

                $url->setBaseUri($baseUri);
                $url->setStaticBaseUri($staticUri);

                return $url;
            }
        );
    }

    /**
     * Initialize the Tag Service.
     */
    protected function initTag()
    {
        $this->di->setShared(
            'tag',
            function () {
                $tag = new Tag;

                $tag->setDocType(Tag::HTML5);
                $tag->setTitleSeparator(' :: ');
                $tag->setTitle('Phalcon WebTools');

                return $tag;
            }
        );
    }

    /**
     * Initialize the Dispatcher.
     */
    protected function initDispatcher()
    {
        $this->di->setShared(
            'dispatcher',
            function () {
                /** @var DiInterface $this */
                $em = $this->get('eventsManager');

                $dispatcher = new MvcDispatcher;
                $dispatcher->setDefaultNamespace('WebTools\Controllers');

                $em->attach('dispatch', $this->getShared('access'), 1000);
                $em->attach('dispatch:beforeException', new DispatchErrorHandler, 999);

                $dispatcher->setEventsManager($em);

                return $dispatcher;
            }
        );
    }

    /**
     * Initialize the Assets Manager.
     */
    protected function initAssets()
    {
        $this->di->setShared(
            'assets',
            function () {
                return new AssetsManager;
            }
        );
    }

    /**
     * Initialize the Session Service.
     */
    protected function initSession()
    {
        $this->di->setShared(
            'session',
            function () {
                $session = new Session;
                $session->start();

                return $session;
            }
        );
    }

    /**
     * Initialize the Flash Service.
     */
    protected function initFlash()
    {
        $this->di->setShared(
            'flash',
            function () {
                return new FlashDirect(
                    [
                        'error'   => 'alert alert-danger fade in',
                        'success' => 'alert alert-success fade in',
                        'notice'  => 'alert alert-info fade in',
                        'warning' => 'alert alert-warning fade in',
                    ]
                );
            }
        );

        $this->di->setShared(
            'flashSession',
            function () {
                return new FlashSession(
                    [
                        'error'   => 'alert alert-danger fade in',
                        'success' => 'alert alert-success fade in',
                        'notice'  => 'alert alert-info fade in',
                        'warning' => 'alert alert-warning fade in',
                    ]
                );
            }
        );
    }

    /**
     * Initialize the Database connection.
     */
    protected function initDatabase()
    {
        $this->di->setShared(
            'db',
            function () {
                /** @var DiInterface $this */
                $em   = $this->getShared('eventsManager');
                $that = $this;

                if ($this->getShared('config')->offsetExists('database')) {
                    $config = $this->getShared('config')->get('database')->toArray();
                } elseif ($this->getShared('config')->offsetExists('db')) {
                    $config = $this->getShared('config')->get('db')->toArray();
                } else {
                    $dbname = sys_get_temp_dir() . DS . 'phalcon.sqlite';
                    $this->getShared('logger')->warning(
                        'Unable to initialize "db" service. Used Sqlite adapter at path: {path}', ['path' => $dbname]
                    );

                    $config = [
                        'adapter' => 'Sqlite',
                        'dbname'  => $dbname,
                    ];
                }

                $adapter = 'Phalcon\Db\Adapter\Pdo\\' . $config;
                unset($config['adapter']);

                /** @var Pdo $connection */
                $connection = new $adapter($config);

                $em->attach(
                    'db',
                    function ($event, $connection) use ($that) {
                        /**
                         * @var Event            $event
                         * @var AdapterInterface $connection
                         * @var DiInterface      $that
                         */
                        if ($event->getType() == 'beforeQuery') {
                            $variables = $connection->getSQLVariables();
                            $string    = $connection->getSQLStatement();

                            if ($variables) {
                                $string .= ' [' . join(', ', $variables) . ']';
                            }

                            $that->getShared('logger')->debug($string);
                        }
                    }
                );

                $connection->setEventsManager($em);

                return $connection;
            }
        );
    }

    /**
     * Initialize the Access Manager.
     */
    protected function initAccessManager()
    {
        $ptoolsIp = $this->ptoolsIp;

        $this->di->setShared(
            'access',
            function () use ($ptoolsIp) {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                $policy = new IpPolicy($ptoolsIp);

                $manager = new AccessManager($policy);
                $manager->setEventsManager($em);

                return $manager;
            }
        );
    }

    /**
     * Initialize the global registry.
     */
    protected function initRegistry()
    {
        $basePath   = $this->basePath;
        $ptoolsPath = $this->ptoolsPath;

        $this->di->setShared(
            'registry',
            function () use ($basePath, $ptoolsPath) {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 * @var FsUtils $fs
                 */
                $registry = new Registry;

                $config  = $this->getShared('config');
                $fsUtils = $this->getShared('fs');

                $ptoolsPath = Text::reduceSlashes(rtrim($ptoolsPath, '\\/'));

                $directories = [
                    'modelsDir'      => null,
                    'controllersDir' => null,
                    'migrationsDir'  => null,
                ];

                if (($application = $config->get('application')) instanceof Config) {
                    foreach ($directories as $name => $value) {
                        if ($possiblePath = $application->get($name)) {
                            if (!$fsUtils->isAbsolute($possiblePath)) {
                                $possiblePath = $basePath . DS . $possiblePath;
                            }

                            if (is_readable($possiblePath) && is_dir($possiblePath)) {
                                $directories[$name] = $fsUtils->normalize($possiblePath);
                            }
                        }
                    }
                }

                $directories['basePath']      = $basePath;
                $directories['ptoolsPath']    = $ptoolsPath;
                $directories['webToolsViews'] = $fsUtils->normalize($ptoolsPath . '/scripts/Phalcon/Web/Tools/Views');
                $directories['resourcesDir']  = $fsUtils->normalize($ptoolsPath . '/resources');
                $directories['elementsDir']   = $fsUtils->normalize($ptoolsPath . '/resources/elements');

                $registry->offsetSet('directories', (object) $directories);

                return $registry;
            }
        );
    }

    /**
     * Initialize utilities.
     */
    protected function initUtils()
    {
        $this->di->setShared(
            'fs',
            function () {
                return new FsUtils;
            }
        );

        $this->di->setShared(
            'resource',
            function () {
                return new AssetsResource;
            }
        );
    }

    /**
     * Initialize User Interface components (mostly HTML elements).
     */
    protected function initUi()
    {
        $this->di->setShared(
            'sidebar',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Registry $registry
                 */
                $registry = $this->getShared('registry');
                $menuItems = $registry->offsetGet('directories')->elementsDir . DS . 'sidebar-menu.php';

                /** @noinspection PhpIncludeInspection */
                $menu = new SidebarMenu(include $menuItems);

                $menu->setDI($this);

                return $menu;
            }
        );
    }
}
