<?php

namespace dokuwiki\plugin\sentry;

/**
 * A Sentry Event
 */
class Event
{
    const CLIENT = 'DokuWiki-SentryPlugin';
    const VERSION = 1;

    // the Sentry log levels
    const LVL_DEBUG = 'debug';
    const LVL_INFO = 'info';
    const LVL_WARN = 'warning';
    const LVL_ERROR = 'error';
    const LVL_FATAL = 'fatal';

    // core error types mapped to severity and name
    const CORE_ERRORS = [
        E_ERROR => [self::LVL_ERROR, 'E_ERROR'],
        E_WARNING => [self::LVL_WARN, 'E_WARNING'],
        E_PARSE => [self::LVL_ERROR, 'E_PARSE'],
        E_NOTICE => [self::LVL_INFO, 'E_NOTICE'],
        E_CORE_ERROR => [self::LVL_ERROR, 'E_CORE_ERROR'],
        E_CORE_WARNING => [self::LVL_WARN, 'E_CORE_WARNING'],
        E_COMPILE_ERROR => [self::LVL_ERROR, 'E_COMPILE_ERROR'],
        E_COMPILE_WARNING => [self::LVL_WARN, 'E_COMPILE_WARNING'],
        E_USER_ERROR => [self::LVL_ERROR, 'E_USER_ERROR'],
        E_USER_WARNING => [self::LVL_WARN, 'E_USER_WARNING'],
        E_USER_NOTICE => [self::LVL_INFO, 'E_USER_NOTICE'],
        E_STRICT => [self::LVL_INFO, 'E_STRICT'],
        E_RECOVERABLE_ERROR => [self::LVL_ERROR, 'E_RECOVERABLE_ERROR'],
        E_DEPRECATED => [self::LVL_WARN, 'E_DEPRECATED'],
        E_USER_DEPRECATED => [self::LVL_WARN, 'E_USER_DEPRECATED'],
    ];

    protected $data = [];

    /**
     * Initialize a new event with all default data
     *
     * @param null|array $data optional merge this data
     */
    public function __construct($data = null)
    {
        $this->data = [];
        $this->data['event_id'] = md5(random_bytes(512));
        $this->data['timestamp'] = gmdate('Y-m-d\TH:i:s');
        $this->data['logger'] = 'default';
        $this->data['level'] = self::LVL_ERROR;
        $this->data['platform'] = 'php';
        $this->data['server_name'] = $_SERVER['SERVER_NAME'];
        $this->data['sdk'] = [
            'name' => self::CLIENT,
            'version' => self::VERSION,
        ];
        $this->data['release'] = getVersion();

        /** @var \helper_plugin_sentry $helper */
        $helper = plugin_load('helper', 'sentry');
        $env = $helper->getConf('env');
        if ($env) {
            $this->data['environment'] = $env;
        }

        $this->data['contexts'] = [];
        $this->initUserContext();
        $this->initHttpContext();
        $this->initAppContext();
        $this->initRuntimeContext();
        $this->initBrowserContext();
        $this->initOsContext();
        $this->initModules();

        if (is_array($data)) {
            $this->data = array_merge($this->data, $data);
        }
    }

    /**
     * Get the ID of this event
     *
     * @return string
     */
    public function getID()
    {
        return $this->data['event_id'];
    }

    /**
     * @param string $level one of the LVL_* constants
     */
    public function setLogLevel($level)
    {
        $this->data['level'] = $level;
    }

    /**
     * Add an exception as cause of this event
     *
     * Recurses into previous exceptions
     *
     * @param \Throwable $e
     */
    public function addException(\Throwable $e)
    {
        if (!is_array($this->data['exception'])) {
            $this->data['exception'] = ['values' => []];
        }

        // ErrorExceptions have a level
        // we set it first, so older Exception overwrite newer ones when they are nested
        if (method_exists($e, 'getSeverity')) {
            $this->setLogLevel($this->errorTypeToSeverity($e->getSeverity()));
        } else {
            $this->setLogLevel(self::LVL_ERROR);
        }

        // log previous exception first
        if ($e->getPrevious() !== null) {
            $this->addException($e->getPrevious());
        }

        // add exception
        $this->data['exception']['values'][] = [
            'type' => get_class($e),
            'value' => $e->getMessage(),
            'stacktrace' => ['frames' => self::backTraceFrames($e->getTrace())],
        ];
    }

    /**
     * Set an error as the cause of this event
     *
     * @param array $error
     */
    protected function setError($error)
    {
        $this->data['exception'] = [
            'values' => [
                [
                    'type' => $this->errorTypeToString($error['type']),
                    'value' => $error['message'],
                    'stacktrace' => [
                        'frames' => [
                            [
                                'filename' => $error['file'],
                                'function' => '',
                                'lineno' => $error['line'],
                                'vars' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->setLogLevel($this->errorTypeToSeverity($error['type']));
    }

    /**
     * @return string
     */
    public function getJSON()
    {
        return json_encode($this->data);
    }

    // region context initializers

    /**
     * Initialize the User Context
     */
    protected function initUserContext()
    {
        global $USERINFO;

        $this->data['user'] = ['ip_address' => $_SERVER['REMOTE_ADDR']];
        if (isset($_SERVER['REMOTE_USER'])) {
            $this->data['user']['username'] = $_SERVER['REMOTE_USER'];
        }
        if (isset($USERINFO['mail'])) {
            $this->data['user']['email'] = $USERINFO['mail'];
        }
    }

    /**
     * Initialize the HTTP Context
     *
     * @fixme this currently does not cover all envionments
     */
    protected function initHttpContext()
    {
        $url = is_ssl() ? 'https://' : 'http://';
        $url .= $_SERVER['HTTP_HOST'];
        $url .= $_SERVER['REQUEST_URI'];

        $this->data['request'] = [
            'url' => $url,
            'method' => $_SERVER['REQUEST_METHOD'],
            'cookies' => $_SERVER['HTTP_COOKIE'],
            'query_string' => $_SERVER['QUERY_STRING'],
        ];

        if (function_exists('apache_request_headers')) {
            $this->data['request']['headers'] = apache_request_headers();
        }

        $this->data['request']['env'] = [
            'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'],
        ];
    }

    /**
     * Initialize App (DokuWiki) Context
     */
    protected function initAppContext()
    {
        $this->data['contexts']['app'] = [
            'app_name' => 'DokuWiki',
            'app_version' => getVersion()
        ];
    }

    /**
     * Initialize Runtime (PHP) Context
     */
    protected function initRuntimeContext()
    {
        $this->data['contexts']['runtime'] = [
            'name' => 'PHP',
            'version' => PHP_VERSION,
            'os' => PHP_OS,
            'sapi' => PHP_SAPI
        ];
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->data['contexts']['runtime']['server'] = $_SERVER['SERVER_SOFTWARE'];
        }
    }

    /**
     * Initialize Browser Context
     */
    protected function initBrowserContext()
    {
        $browser = new Browser();
        $this->data['contexts']['browser'] = [
            'ua' => $_SERVER['HTTP_USER_AGENT'],
            'name' => $browser->getBrowser(),
            'version' => $browser->getVersion(),
        ];
    }

    /**
     * Initialize OS Context
     */
    protected function initOsContext()
    {
        $browser = new Browser();
        $this->data['contexts']['os'] = [
            'name' => $browser->getPlatform(),
        ];
    }

    /**
     * Adds the enabled plugins and the current template to the modules section
     */
    protected function initModules()
    {
        $this->data['modules'] = [];
        $this->addPluginsToModules();
        $this->addTemplateToModules();
    }

    /**
     * Writes the enabled plugins and their version to the modules section
     *
     * If a plugin.info.txt can not be read, than an error message is recorded instead of the version
     *
     * see https://docs.sentry.io/clientdev/attributes/#optional-attributes
     */
    protected function addPluginsToModules()
    {
        /* @var \Doku_Plugin_Controller $plugin_controller */
        global $plugin_controller;
        $pluginlist = $plugin_controller->getList('', false);
        foreach ($pluginlist as $pluginName) {
            $infopath = DOKU_PLUGIN . $pluginName . '/plugin.info.txt';
            if (is_readable($infopath)) {
                $pluginInfo = confToHash($infopath);
                $this->data['modules']['plugin.' . $pluginName] = $pluginInfo['date'];
            } else {
                $this->data['modules']['plugin.' . $pluginName] = 'plugin.info.txt unreadable';
            }
        }
    }

    /**
     * Writes the current template and its version to the modules section
     *
     * If a template.info.txt can not be read, than an error message is recorded instead of the version
     *
     * see https://docs.sentry.io/clientdev/attributes/#optional-attributes
     */
    protected function addTemplateToModules()
    {
        global $conf;
        $tplpath = DOKU_TPLINC . 'template.info.txt';
        if (is_readable($tplpath)) {
            $templateInfo = confToHash($tplpath);
            $this->data['modules']['template.' . $conf['template']] = $templateInfo['date'];
        } else {
            $this->data['modules']['template.' . $conf['template']] = 'template.info.txt unreadable';
        }
    }

    // endregion

    /**
     * Translate a PHP Error constant into a Sentry log level group
     *
     * @param int $type PHP E_$x error constant
     * @return string          Sentry log level group
     */
    protected function errorTypeToSeverity($type)
    {
        if(isset(self::CORE_ERRORS[$type])) return self::CORE_ERRORS[$type][0];
        return self::LVL_ERROR;
    }

    /**
     * Get the PHP Error constant as string for logging purposes
     *
     * @param int $type PHP E_$x error constant
     * @return string       E_$x error constant as string
     */
    protected function errorTypeToString($type)
    {
        if(isset(self::CORE_ERRORS[$type])) return self::CORE_ERRORS[$type][1];
        return 'E_UNKNOWN_ERROR_TYPE';
    }

    /**
     * Convert a PHP backtrace to Sentry stacktrace frames
     *
     * @param array $trace
     * @return array
     */
    public static function backTraceFrames($trace)
    {
        $frames = [];
        foreach (array_reverse($trace) as $frame) {
            $frames[] = [
                'filename' => $frame['file'],
                'function' => $frame['function'],
                'lineno' => $frame['line'],
                'vars' => $frame['args'],
            ];
        }
        return $frames;
    }

    // region factory methods

    /**
     * Load an event from JSON encoded data
     *
     * @param string $json
     * @return Event
     */
    static public function fromJSON($json)
    {
        return new Event(json_decode($json, true));
    }

    /**
     * Generate an event from a exception
     *
     * @param \Throwable $e
     * @return Event
     */
    static public function fromException(\Throwable $e)
    {
        $ev = new Event();
        $ev->addException($e);
        return $ev;
    }

    /**
     * Generate an event from an error
     *
     * Errors can be obtained via error_get_last()
     *
     * @param array $error
     * @return Event
     */
    public static function fromError($error)
    {
        $ev = new Event();
        $ev->setError($error);
        return $ev;
    }

    // endregion

}
