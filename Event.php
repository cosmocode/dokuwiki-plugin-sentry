<?php

namespace dokuwiki\plugin\sentry;

class Event
{

    const CLIENT = 'DokuWiki-SentryPlugin';
    const VERSION = 1;

    const LVL_DEBUG = 'debug';
    const LVL_INFO = 'info';
    const LVL_WARN = 'warning';
    const LVL_ERROR = 'error';
    const LVL_FATAL = 'fatal';

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

        $this->data['contexts'] = [];
        $this->initUserContext();
        $this->initHttpContext();
        $this->initAppContext();
        $this->initRuntimeContext();
        $this->initBrowserContext();
        $this->initOsContext();

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
     * Add the exception to the event
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
            $this->setLogLevel($this->translateSeverity($e->getSeverity()));
        } else {
            $this->setLogLevel(self::LVL_ERROR);
        }

        // log previous exception first
        if ($e->getPrevious() !== null) $this->addException($e->getPrevious());

        // prepare stack trace
        $stack = [];
        foreach (array_reverse($e->getTrace()) as $frame) {
            $stack[] = [
                'filename' => $frame['file'],
                'function' => $frame['function'],
                'lineno' => $frame['line'],
                'vars' => $frame['args']
            ];
        }


        // add exception
        $this->data['exception']['values'][] = [
            'type' => get_class($e),
            'value' => $e->getMessage(),
            'stacktrace' => ['frames' => $stack]
        ];
    }

    /**
     * @return string
     */
    public function getJSON()
    {
        return json_encode($this->data);
    }

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
     * Translate a PHP Error constant into a Sentry log level group
     *
     * @param int $severity PHP E_$x error constant
     * @return string          Sentry log level group
     */
    protected function translateSeverity($severity)
    {
        switch ($severity) {
            case E_ERROR:
                return self::LVL_ERROR;
            case E_WARNING:
                return self::LVL_WARN;
            case E_PARSE:
                return self::LVL_ERROR;
            case E_NOTICE:
                return self::LVL_INFO;
            case E_CORE_ERROR:
                return self::LVL_ERROR;
            case E_CORE_WARNING:
                return self::LVL_WARN;
            case E_COMPILE_ERROR:
                return self::LVL_ERROR;
            case E_COMPILE_WARNING:
                return self::LVL_WARN;
            case E_USER_ERROR:
                return self::LVL_ERROR;
            case E_USER_WARNING:
                return self::LVL_WARN;
            case E_USER_NOTICE:
                return self::LVL_INFO;
            case E_STRICT:
                return self::LVL_INFO;
            case E_RECOVERABLE_ERROR:
                return self::LVL_ERROR;
            case E_DEPRECATED:
                return self::LVL_WARN;
            case E_USER_DEPRECATED:
                return self::LVL_WARN;
            default:
                return self::LVL_ERROR;
        }
    }

    /**
     * Get the PHP Error constant as string for logging purposes
     *
     * @param int     $type PHP E_$x error constant
     * @return string       E_$x error constant as string
     */
    protected function errorTypeToString($type)
    {
        switch ($type) {
            case E_ERROR:
                return 'E_ERROR';
            case E_WARNING:
                return 'E_WARNING';
            case E_PARSE:
                return 'E_PARSE';
            case E_NOTICE:
                return 'E_NOTICE';
            case E_CORE_ERROR:
                return 'E_CORE_ERROR';
            case E_CORE_WARNING:
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR:
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING:
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR:
                return 'E_USER_ERROR';
            case E_USER_WARNING:
                return 'E_USER_WARNING';
            case E_USER_NOTICE:
                return 'E_USER_NOTICE';
            case E_STRICT:
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR:
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED:
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED:
                return 'E_USER_DEPRECATED';
            default:
                return 'E_UNKNOWN_ERROR_TYPE';
        }
    }

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
     * @param array $error
     *
     * @return Event
     */
    public static function fromError($error)
    {

        $ev = new Event();
        $ev->setError($error);
        return $ev;
    }

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
        $this->setLogLevel($this->translateSeverity($error['type']));
    }

}
