<?php

namespace dokuwiki\plugin\sentry\conf;

use dokuwiki\plugin\config\core\Setting\SettingNumeric;
use dokuwiki\plugin\sentry\Event;

/**
 * Custom settings class
 */
class Setting extends SettingNumeric
{
    /** @inheritdoc */
    public function initialize($default = null, $local = null, $protected = null)
    {
        parent::initialize($default, $local, $protected);

        // our default is PHP's error reporting
        $this->default = error_reporting();
        if ($local == 0) {
            $this->local = $this->default;
        }
    }

    /** @inheritdoc */
    protected function cleanValue($value)
    {
        if ($value === null) return null;
        if (is_array($value)) $value = array_sum($value);

        return (int)$value;
    }

    /** @inheritdoc */
    public function isDefault()
    {
        return parent::isDefault() || (error_reporting() == $this->local);
    }

    /** @inheritdoc */
    public function html(\admin_plugin_config $plugin, $echo = false)
    {

        if ($echo) {
            $current = $this->input;
        } else {
            $current = $this->local;
        }

        $label = '<label>' . $this->prompt($plugin) . '</label>';
        $input = '';

        foreach (Event::CORE_ERRORS as $val => $info) {
            $checked = '';
            if ($current & $val) {
                $checked = 'checked="checked"';
            }

            $class = 'selection';
            if (error_reporting() & $val) {
                if ($current & $val) {
                    $class .= ' selectiondefault';
                }
            } else {
                if (!($current & $val)) {
                    $class .= ' selectiondefault';
                }
            }

            $inputId = 'config___' . $this->key . '_' . $val;

            $input .= '<div class="' . $class . '">';
            $input .= '<label for="' . $inputId . '">' . hsc($info[1]) . '</label>';
            $input .= '<input type="checkbox" id="' . $inputId . '" name="config[' . $this->key . '][]" ' .
                'value="' . $val . '" ' . $checked . ' />';
            $input .= '</div>';
        }

        $input .= '<div class="selection">' . $current . '</div>';

        return [$label, $input];
    }

}
