<?php

namespace dokuwiki\plugin\sentry;

class Event
{

    const CLIENT = 'DokuWiki-SentryPlugin';
    const VERSION = 1;

    protected $data = [];

    public function __construct($data = null)
    {
        $event = [];
        $event['event_id'] = md5(random_bytes(512));
        $event['timestamp'] = gmdate('Y-m-d\TH:i:s');
        $event['logger'] = 'default';
        $event['platform'] = 'php';
        $event['sdk'] = [
            'name' => self::CLIENT,
            'version' => self::VERSION,
        ];

        // user context
        $event['user'] = [
            'ip_address' => $_SERVER['REMOTE_ADDR'],
        ];
        if (isset($_SERVER['REMOTE_USER'])) {
            $event['user']['username'] = $_SERVER['REMOTE_USER'];
        }

        if (is_array($data)) {
            $event = array_merge($event, $data);
        }
        $this->data = $event;

        // FIXME make other contexts from this
        $event['tags'] = [
            'dokuwiki' => getVersion(),
            'os' => PHP_OS,
            'php' => PHP_VERSION,
        ];
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
