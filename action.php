<?php
/**
 * DokuWiki Plugin sentry (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki

if (!defined('DOKU_INC')) {
    die();
}

class action_plugin_sentry extends DokuWiki_Action_Plugin
{

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        // catch all exceptions
        set_exception_handler([$this, 'exceptionHandler']);
        // turn errors into exceptions
        set_error_handler([$this, 'errorConverter']);
        // log fatal errors
        register_shutdown_function([$this, 'fatalHandler']);

        // retry to send pending events
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'handle_indexer');
    }

    /**
     * [Custom event handler which performs action]
     *
     * Called for event:
     *
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     *
     * @return void
     */
    public function handle_indexer(Doku_Event $event, $param)
    {
        /** @var helper_plugin_sentry $helper */
        $helper = plugin_load('helper', 'sentry');
        $events = $helper->getPendingEventIDs();
        if (!count($events)) return;

        $event->preventDefault();
        $event->stopPropagation();

        foreach ($events as $eid) {
            $event = $helper->loadEvent($eid);
            if ($event === null) continue;
            if ($helper->sendEvent($event)) {
                $helper->deleteEvent($eid);
            }
        }
    }

    /**
     * Log errors that killed the application
     */
    public function fatalHandler()
    {
        $error = error_get_last();
        if ($error !== null) return;

        $e = new \ErrorException($error['message'], $error['type'], 1, $error['file'], $error['line']);
        $this->exceptionHandler($e);
    }

    /**
     * Send exceptions to sentry
     *
     * @param Throwable $e
     */
    public function exceptionHandler(\Throwable $e)
    {
        /** @var helper_plugin_sentry $helper */
        $helper = plugin_load('helper', 'sentry');
        $helper->logException($e);
        $helper->showException($e);
    }

    /**
     * Error handler to convert old school warnings, notices, etc to exceptions
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return bool
     * @throws \ErrorException
     */
    public function errorConverter($errno, $errstr, $errfile, $errline)
    {
        if (!(error_reporting() & $errno)) {
            // This error code is not included in error_reporting, so let it fall
            // through to the standard PHP error handler
            return false;
        }
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

}

