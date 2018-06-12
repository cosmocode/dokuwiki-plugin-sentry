<?php

/**
 * DokuWiki Plugin sentry (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <dokuwiki@cosmocode.de>
 */
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
        if (!$this->getConf('dsn')) {
            return;
        }
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
                        'stacktrace' => [
                            'frames' => $this->parseJavaScriptStacktrace($INPUT->str('stack'))
                        ],
                    ],
                ],
            ],
        ];
        $sentryData = array_merge($sentryData, $INPUT->arr('additionalData'));
        $sentryData['extra']['original_stack'] = $INPUT->str('stack');

        $sentryEvent = new \dokuwiki\plugin\sentry\Event($sentryData);

        /** @var helper_plugin_sentry $sentryHelper */
        $sentryHelper = plugin_load('helper', 'sentry');
        $sentryHelper->logEvent($sentryEvent);
    }

    /**
     * Tries to parse a JavaScript stack trace into sentry frames
     *
     * @see https://github.com/errwischt/stacktrace-parser/blob/master/lib/stacktrace-parser.js
     * @param string $trace
     * @return array
     */
    protected function parseJavaScriptStacktrace($trace)
    {
        $chrome = '/^\s*at (?:(?:(?:Anonymous function)?|((?:\[object object\])?\S+' .
            '(?: \[as \S+\])?)) )?\(?((?:file|http|https):.*?):(\d+)(?::(\d+))?\)?\s*$/i';
        $gecko = '/^(?:\s*([^@]*)(?:\((.*?)\))?@)?(\S.*?):(\d+)(?::(\d+))?\s*$/i';

        $frames = [];
        $lines = explode("\n", $trace);
        foreach ($lines as $line) {
            if (preg_match($gecko, $line, $parts)) {
                $frames[] = [
                    'filename' => $parts[3] ? $parts[3] : '<unknown file>',
                    'function' => $parts[1] ? $parts[1] : '<unknown function>',
                    'lineno' => (int)$parts[4],
                    'colno' => (int)$parts[5]
                ];
            } elseif (preg_match($chrome, $line, $parts)) {
                $frames[] = [
                    'file' => $parts[2] ? $parts[2] : '<unknown file>',
                    'function' => $parts[1] ? $parts[1] : '<unknown function>',
                    'lineno' => (int)$parts[3],
                    'colno' => (int)$parts[4]
                ];
            }
        }
        return $frames;
    }
}
