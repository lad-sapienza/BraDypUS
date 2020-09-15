<?php

namespace Tempate\Parts;


class FormElement
{
    private $common_attributes = '';

    public function __construct( array $settings, string $setting )
    {
        $att_arr = [
            'name' => $setting['name'],
            'id' => $setting['id'],
            'autocomplete' => 'on',
        ];
        if( $setting['onchange'] ) {
            $att_arr['onchange'] = $setting['onchange'];
        }
        if( $setting['pattern'] ) {
            $att_arr['mypattern'] = $setting['pattern'];
        }
        if( $setting['ondblclick'] ) {
            $att_arr['ondblclick'] = $setting['ondblclick'];
        }
        if( $setting['check'] ) {
            $att_arr['check'] = $setting['check'];
        }
        if (preg_match('/plg/', $setting['name'])) {
            $att_arr['data-changeonchange'] = $setting['changeonchange'];
        }
        if ( $setting['def_value'] && $context === 'add_new' ) {
            $att_arr['changed'] = 'auto';
        }
        if ( $setting['def_value'] && $this->context === 'edit' && $setting['force_default'] ) {
            $att_arr['changed'] = 'auto';
        }


        foreach ($att_arr as $key => $value) {
            $this->common_attributes .= ' ' . $key . '="' . $value . '" ';
        }
        $this->common_attributes .= $setting['disabled'] . $setting['readonly'];

        
    }

    public function Slider(string $value = null, string $min = null, string $max = null) : string
    {
		return '<input type="range" class="slider" ' . $this->common_attributes . ' value="' . $value . '"'
        .  ($min ? 'min="' . $min. '"' : '')
        .  ($max ? 'max="' . $max . '"' : '')
        . ' />';
    }
}