<?php
/** Olivia — theme settings schema (Admin > Appearance > Theme Settings). */
return [
    ['key' => 'accent_color', 'label' => 'Accent Color', 'type' => 'color', 'default' => '#7c3aed', 'desc' => 'Buttons, links, highlights and the progress bar.'],
    ['key' => 'default_mode', 'label' => 'Default Appearance', 'type' => 'select', 'default' => 'light', 'options' => ['light' => 'Light', 'dark' => 'Dark'], 'desc' => 'Visitors can override with the sun/moon toggle (remembered on their device).'],
    ['key' => 'show_hero', 'label' => 'Show Homepage Hero', 'type' => 'checkbox', 'default' => '1'],
    ['key' => 'hero_title', 'label' => 'Hero Title', 'type' => 'text', 'default' => '', 'desc' => 'Leave empty to use the site title.'],
    ['key' => 'hero_text', 'label' => 'Hero Text', 'type' => 'textarea', 'default' => '', 'desc' => 'Leave empty to use the site tagline.'],
    ['key' => 'about_text', 'label' => 'About Section Text', 'type' => 'textarea', 'default' => '', 'desc' => 'Shown in the About strip on the homepage.'],
    ['key' => 'sidebar', 'label' => 'Sidebar Layout (posts)', 'type' => 'select', 'default' => 'right', 'options' => ['right' => 'Right sidebar', 'left' => 'Left sidebar', 'none' => 'No sidebar (full width)']],
    ['key' => 'footer_text', 'label' => 'Footer Text', 'type' => 'text', 'default' => '', 'desc' => 'Leave empty for the site tagline.'],
    ['key' => 'custom_css', 'label' => 'Custom CSS', 'type' => 'textarea', 'default' => ''],
];
