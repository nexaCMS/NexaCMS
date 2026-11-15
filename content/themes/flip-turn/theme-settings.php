<?php
/** Flip Turn — theme settings schema (Admin > Appearance > Theme Settings). */
return [
    ['key' => 'accent_color', 'label' => 'Accent Color', 'type' => 'color', 'default' => '#A7DDFF', 'desc' => 'Menu hover/active background, buttons and the nav underline.'],
    ['key' => 'show_tagline', 'label' => 'Show Tagline in Hero', 'type' => 'checkbox', 'default' => '1'],
    ['key' => 'sidebar', 'label' => 'Sidebar Layout', 'type' => 'select', 'default' => 'right', 'options' => ['right' => 'Right sidebar', 'left' => 'Left sidebar', 'none' => 'No sidebar (full width)']],
    ['key' => 'footer_text', 'label' => 'Footer Text', 'type' => 'text', 'default' => '', 'desc' => 'Leave empty for the site tagline.'],
    ['key' => 'custom_css', 'label' => 'Custom CSS', 'type' => 'textarea', 'default' => ''],
];
