<?php
/** Multi — theme settings schema (Admin > Appearance > Theme Settings). */
return [
    ['key' => 'slider', 'label' => 'Homepage Hero Slider', 'type' => 'checkbox', 'default' => '1', 'setting' => 'multi_slider', 'desc' => 'Same switch as on the Themes screen.'],
    ['key' => 'footer_text', 'label' => 'Footer About Text', 'type' => 'text', 'default' => '', 'desc' => 'First footer column. Leave empty for the site tagline.'],
    ['key' => 'custom_css', 'label' => 'Custom CSS', 'type' => 'textarea', 'default' => ''],
];
