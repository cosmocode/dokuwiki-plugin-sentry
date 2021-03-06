<?php

use dokuwiki\plugin\sentry\Event;

/**
 * DokuWiki Plugin sentry (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Michael Große <dokuwiki@cosmocode.de>
 */
class helper_plugin_sentry extends DokuWiki_Plugin
{
    /**
     * Parse the DSN configuration into its parts
     *
     * @return array
     */
    protected function parseDSN()
    {
        $parts = parse_url($this->getConf('dsn'));
        $dsn = [];
        $dsn['protocol'] = $parts['scheme'];
        $dsn['public'] = $parts['user'];
        $dsn['secret'] = $parts['pass'];
        $dsn['project'] = (int)basename($parts['path']);
        $dsn['url'] = $parts['host'];
        if (!empty($parts['port'])) $dsn['url'] .= ':' . $parts['port'];

        $path = dirname($parts['path']);
        $path = trim($path, '/');
        if (!empty($path)) {
            $path = '/' . $path;
        }
        $dsn['path'] = $path;

        return $dsn;
    }

    /**
     * Return the API endpoint to store messages
     *
     * @return string
     */
    protected function storeAPI()
    {
        $dsn = $this->parseDSN();
        return $dsn['protocol'] . '://' . $dsn['url'] . $dsn['path'] . '/api/' . $dsn['project'] . '/store/';
    }

    /**
     * Return the X-Sentry-Auth header
     *
     * @return string
     */
    protected function storeAuthHeader()
    {
        $dsn = $this->parseDSN();

        $header[] = 'Sentry sentry_version=7';
        $header[] = 'sentry_client=' . Event::CLIENT . Event::VERSION;
        $header[] = 'sentry_timestamp=' . time();
        $header[] = 'sentry_key=' . $dsn['public'];
        $header[] = 'sentry_secret=' . $dsn['secret'];

        return join(', ', $header);
    }

    /**
     * Log an exception
     *
     * If you need more control over the logged Event, use logEvent()
     *
     * @param \Throwable|\Exception $e
     */
    public function logException($e)
    {
        $this->logEvent(Event::fromException($e));

    }

    /**
     * Log an event
     *
     * @param Event $event
     */
    public function logEvent(Event $event)
    {
        $this->saveEvent($event);
        if ($this->sendEvent($event)) $this->deleteEvent($event->getID());
    }


    /**
     * Log a message and optionally some data to sentry
     *
     * @param string $message the raw message string
     * @param array  $extra
     */
    public function logMessage($message, array $extra = [])
    {
        $backtrace = debug_backtrace();
        array_shift($backtrace); // remove this logMessage method

        $eventData = [
            'sentry.interfaces.Message' => [
                'message' => $message,
            ],
            'stacktrace' => ['frames' => Event::backTraceFrames($backtrace)],
            'extra' => $extra,
        ];

        $event = new Event($eventData);
        $event->setLogLevel('info');
        $this->logEvent($event);
    }

    /**
     * Format an exception for the user in HTML
     *
     * @param \Throwable|\Exception $e
     * @return string the HTML
     */
    public function formatException($e)
    {
        global $conf;
        $html = '<div style="width:60%; margin: auto; background-color: #fcc;
                border: 1px solid #faa; padding: 0.5em 1em; font-family: sans-serif">';
        $html .= '<h1>An error occured</h1>';
        $html .= '<p>' . hsc(get_class($e)) . ': ' . $e->getMessage() . '</p>';
        if ($conf['allowdebug']) {
            $html .= '<p><code>' . hsc($e->getFile()) . ':' . hsc($e->getLine()) . '</code></p>';
            $html .= '<pre>' . hsc($e->getTraceAsString()) . '</pre>';
        }
        $html .= '<p>The error has been logged.</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Save the given event to file system
     *
     * @param Event $event
     */
    public function saveEvent(Event $event)
    {
        global $conf;
        $cachedir = $conf['cachedir'] . '/_sentry/';
        $file = $cachedir . $event->getID() . '.json';
        io_makeFileDir($file);
        file_put_contents($file, $event->getJSON());
    }

    /**
     * Load a pending event
     *
     * @param string $id
     * @return Event|null
     */
    public function loadEvent($id)
    {
        global $conf;
        $cachedir = $conf['cachedir'] . '/_sentry/';
        $file = $cachedir . $id . '.json';
        if (!file_exists($file)) return null;
        $json = file_get_contents($file);
        return Event::fromJSON($json);
    }

    /**
     * Delete a pending event
     *
     * @param string $id
     */
    public function deleteEvent($id)
    {
        global $conf;
        $cachedir = $conf['cachedir'] . '/_sentry/';
        $file = $cachedir . $id . '.json';
        // the event may have been deleted in the meantime
        @unlink($file);
    }

    /**
     * Returns a list of event IDs that have not yet been sent
     *
     * @return string[]
     */
    public function getPendingEventIDs()
    {
        global $conf;
        $cachedir = $conf['cachedir'] . '/_sentry/';

        $files = glob($cachedir . '/*.json');
        return array_map(function ($in) {
            return basename($in, '.json');
        }, $files);
    }

    /**
     * Send the given event to sentry
     *
     * You most probably want to use logEvent() or logException() instead
     *
     * @param Event $event the event
     * @return bool was the event submitted successfully?
     */
    public function sendEvent(Event $event)
    {
        if (class_exists('dokuwiki\HTTP\DokuHTTPClient')) {
            $http = new dokuwiki\HTTP\DokuHTTPClient();
        } else {
            $http = new DokuHTTPClient();
        }
        $http->timeout = 4; // this should not take long!
        $http->headers['User-Agent'] = Event::CLIENT . Event::VERSION;
        $http->headers['X-Sentry-Auth'] = $this->storeAuthHeader();
        $http->headers['Content-Type'] = 'application/json';
        $ok = $http->post($this->storeAPI(), $event->getJSON());
        if (!$ok) dbglog($http->resp_body, 'Sentry returned Error');
        return (bool)$ok;
    }

    /**
     * Return the wanted error reporting
     *
     * @return int
     */
    public function errorReporting() {
        $conf = (int) $this->getConf('errors');
        if($conf === 0) return error_reporting();
        return $conf;
    }
}

