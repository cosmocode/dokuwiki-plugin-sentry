<?php

class action_plugin_sentry_ajax extends DokuWiki_Action_Plugin
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
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    /**
     *
     * This uses event AJAX_CALL_UNKNOWN
     *
     * @param Doku_Event $event
     * @param            $param
     */
    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'plugin_sentry') {
            return;
        }
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;

        $sentryData = [
            'logger' => 'javascript',
            'exception' => [
                'values' => [
                    [
                        'type' => $INPUT->str('name'),
                        'value' => $INPUT->str('message'),
                        'stacktrace' => $this->parseJavaScriptStracktrace($INPUT->str('stack')),
                    ],
                ],
            ],
        ];
        $sentryData = array_merge($sentryData, $INPUT->arr('additionalData'));

        $sentryEvent = new \dokuwiki\plugin\sentry\Event($sentryData);

        /** @var helper_plugin_sentry $sentryHelper */
        $sentryHelper = plugin_load('helper', 'sentry');
        $sentryHelper->logEvent($sentryEvent);
    }

    protected function parseJavaScriptStracktrace($trace) {
        return [
            'frames' => [
                [
                    'filename' => 'abc.js',
                    'function' => 'foo()',
                    'lineno' => 57,
                    'vars' => [],
                ],
            ],
        ];
    }
}
