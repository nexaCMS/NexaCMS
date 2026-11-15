<?php
/** Darrs — theme settings schema (Admin > Appearance > Theme Settings). */
return [
    ['key' => 'accent_color', 'label' => 'Accent Color', 'type' => 'color', 'default' => '#1396d4', 'desc' => 'Logo accent and Read More links.'],
    ['key' => 'show_search', 'label' => 'Show Header Search Box', 'type' => 'checkbox', 'default' => '1'],
    ['key' => 'footer_text', 'label' => 'Footer Text', 'type' => 'text', 'default' => '', 'desc' => 'Shown above the closing footer area.'],
    ['key' => 'custom_css', 'label' => 'Custom CSS', 'type' => 'textarea', 'default' => ''],
];
