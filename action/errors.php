<?php

use dokuwiki\plugin\sentry\Event;

/**
 * DokuWiki Plugin sentry (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Michael Große <dokuwiki@cosmocode.de>
 */
class action_plugin_sentry_errors extends DokuWiki_Action_Plugin
{

    protected $lastHandledError;

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        if (!$this->getConf('dsn')) {
            return;
        }

        // catch all exceptions
        set_exception_handler([$this, 'exceptionHandler']);
        // log non fatal errors
        set_error_handler([$this, 'errorHandler']);
        // log fatal errors
        register_shutdown_function([$this, 'fatalHandler']);

        // retry to send pending events
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'handle_indexer');
    }

    /**
     * Send pending tasks on indexer run
     *
     * Called for event: INDEXER_TASKS_RUN
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
        if (!count($events)) {
            return;
        }

        $event->preventDefault();
        $event->stopPropagation();

        foreach ($events as $eid) {
            $event = $helper->loadEvent($eid);
            if ($event === null) {
                continue;
            }
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
        if ($error === null) {
            return;
        }
        // was this error already processed in error handler? ignore it
        if ($error == $this->lastHandledError) {
            return;
        }

        /** @var helper_plugin_sentry $helper */
        $helper = plugin_load('helper', 'sentry');
        $event = Event::fromError($error);
        $helper->logEvent($event);
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
        echo $helper->formatException($e);
    }

    /**
     * Error handler to log old school warnings, notices, etc
     *
     * @param int $type
     * @param string $message
     * @param string $file
     * @param int $line
     * @return false we always let the default handler continue
     */
    public function errorHandler($type, $message, $file, $line)
    {
        $error = compact('type', 'message', 'file', 'line');
        $this->lastHandledError = $error;

        // error_reporting = 0 -> error was supressed, never handle it
        if (error_reporting() === 0) {
            return false;
        }

        /** @var helper_plugin_sentry $helper */
        $helper = plugin_load('helper', 'sentry');

        // Check if this error code is wanted for sentry logging
        if (!($helper->errorReporting() & $type)) {
            return false;
        }

        // log it
        $event = Event::fromError($error);
        $helper->logEvent($event);

        return false;
    }

}

