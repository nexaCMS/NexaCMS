<?php
/** Nova Blog — theme settings schema (Admin > Appearance > Theme Settings). */
return [
    ['key' => 'accent_color', 'label' => 'Accent Color', 'type' => 'color', 'default' => '#4f46e5', 'desc' => 'Buttons, active menu item, hero gradient and link hovers.'],
    ['key' => 'show_hero', 'label' => 'Show Homepage Hero', 'type' => 'checkbox', 'default' => '1'],
    ['key' => 'hero_title', 'label' => 'Hero Title', 'type' => 'text', 'default' => '', 'desc' => 'Leave empty to use the site title.'],
    ['key' => 'hero_text', 'label' => 'Hero Text', 'type' => 'textarea', 'default' => '', 'desc' => 'Leave empty to use the site tagline.'],
    ['key' => 'sidebar', 'label' => 'Sidebar Layout', 'type' => 'select', 'default' => 'right', 'options' => ['right' => 'Right sidebar', 'left' => 'Left sidebar', 'none' => 'No sidebar (full width)']],
    ['key' => 'footer_text', 'label' => 'Footer Text', 'type' => 'text', 'default' => '', 'desc' => 'Leave empty for the site tagline.'],
    ['key' => 'custom_css', 'label' => 'Custom CSS', 'type' => 'textarea', 'default' => ''],
];
