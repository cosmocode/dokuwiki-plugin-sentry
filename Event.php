<?php

namespace dokuwiki\plugin\sentry;

class Event
{

    const CLIENT = 'DokuWiki-SentryPlugin';
    const VERSION = 1;

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
        $this->data['platform'] = 'php';
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

        // log previous exception first
        if ($e->getPrevious() !== null) $this->addException($e->getPrevious());

        // prepare stack trace
        $stack = [];
        foreach ($e->getTrace() as $frame) {
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

}
