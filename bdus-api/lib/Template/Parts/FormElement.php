<?php

namespace Template\Parts;


class FormElement
{
    private $common_attributes = '';
    private $settings;

    public function __construct( array $settings )
    {
        $this->settings = $settings;

        $att_arr = [
            'name' => $settings['name'],
            'id' => $settings['id'],
            'autocomplete' => 'on',
        ];
        if( $settings['onchange'] ) {
            $att_arr['onchange'] = $settings['onchange'];
        }
        if( $settings['pattern'] ) {
            $att_arr['mypattern'] = $settings['pattern'];
        }
        if( $settings['ondblclick'] ) {
            $att_arr['ondblclick'] = $settings['ondblclick'];
        }
        if( $settings['check'] ) {
            $att_arr['check'] = $settings['check'];
        }
        if (preg_match('/plg/', $settings['name'])) {
            $att_arr['data-changeonchange'] = $settings['changeonchange'];
        }
        if ( $settings['def_value'] && $context === 'add_new' ) {
            $att_arr['changed'] = 'auto';
        }
        if ( $settings['def_value'] && $this->context === 'edit' && $settings['force_default'] ) {
            $att_arr['changed'] = 'auto';
        }


        foreach ($att_arr as $key => $value) {
            $this->common_attributes .= ' ' . $key . '="' . $value . '" ';
        }
        $this->common_attributes .= $settings['disabled'] . $settings['readonly'];
    }

    public function Input() : string
    {
        return '<input type="text" ' .
                    'class="form-control"' .
                    $this->common_attributes .
                    $this->settings['maxlength'] .
                    ($this->settings['direction'] === 'rtl' ? ' style="direction: rtl;" ' : '') .
                    'value="' . $this->settings['html_data'] . '" ' .
                '>';
    }

    public function Date() : string
    {
        return '<input type="date" ' .
                    'class="form-control" ' .
                    $this->common_attributes .
                    $this->settings['maxlength'] .
                    'value="' . $this->settings['html_data'] . '" ' .
                    ($this->settings['direction'] === 'rtl' ? ' style="direction: rtl;" ' : '') .
                    'value="' . $settings['html_data'] . 
                '">';
    }

    public function Textarea() : string
    {
        return '<textarea ' .
                $this->common_attributes .
                ($this->settings['direction']  === 'rtl' ? ' style="direction: rtl;" ' : '') .
                $this->settings['maxlength'] .
                ($this->settings['height'] ? 'rows="' . $this->settings['height'] . '"': '') .
                ' class="form-control">' .
                    $this->settings['data'] .
             '</textarea>';
    }

    public function Select(bool $is_multi = false, bool $is_combo = false) : string
    {
        $html = '<select ' . 
                    $this->common_attributes .
                    ($this->settings['direction']  === 'rtl' ? ' style="direction: rtl;" ' : '') .
                    ($is_multi ? ' multiple="multiple" ' : '') .
                    ($is_combo ? ' data-tags="true" ' : '') .
                    ($this->settings['vocabulary_set'] ? ' data-context="vocabulary_set" data-att="' . $this->settings['vocabulary_set'] . '"' : '') .
                    ($this->settings['get_values_from_tb'] ? ' data-context="get_values_from_tb" data-att="' . $this->settings['get_values_from_tb'] . '"' : '') .
                    ($this->settings['id_from_tb'] ? ' data-context="id_from_tb" data-att="' . $this->settings['id_from_tb'] . '"' : '') .
                ' class="form-control">' .
                '<option></option>';

        if (is_array($this->settings['menu_values'])) {
            foreach ($this->settings['menu_values'] as $k => $v) {
                $html .= '<option value="' . $k . '" selected="selected" >' . $v . '</option>';
            }
        }
        $html .= '</select>';

        return $html;
    }
    
    public function ComboSelect() : string
    {
        return $this->Select(false, true);
    }

    public function MultiSelect() : string
    {
        return $this->Select(true, true);
    }

    public function Boolean() : string
    {
        return '<select ' . 
                    $this->common_attributes .
                    ' class="form-control">'
            . '<option value="1"' . (($this->settings['data'] === 1) ? ' selected ': '') . '>' . \tr::get('yes') . '</option>'
            . '<option value="0"' . ((!$this->settings['data'] === 0 or $this->settings['data']==0) ? ' selected ': '') . '>' . \tr::get('no') . '</option>'
        . '</select>';
    }

    public function Slider() : string
    {
		return '<input type="range" class="slider" ' . $this->common_attributes . ' value="' . $this->settings['value'] . '"'
        .  ($this->settings['min'] ? 'min="' . $this->settings['min']. '"' : '')
        .  ($this->settings['max'] ? 'max="' . $this->settings['max'] . '"' : '')
        . ' />';
    }
}