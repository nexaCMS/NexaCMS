<?php
/**
 * Calc-Age Shortcode Plugin for UPCMS
 * Usage: [calc-age day="15" month="06" year="1995"]
 */

function up_calc_age_shortcode(array $data): string {
    if (isset($data['day'], $data['month'], $data['year'])) {
        $age = (int) date('Y') - (int) $data['year'];
        if ((int) date('m') < (int) $data['month'] || ((int) date('m') === (int) $data['month'] && (int) date('d') < (int) $data['day'])) {
            $age--;
        }
        return (string) $age;
    }
    return '';
}

add_shortcode('calc-age', 'up_calc_age_shortcode');

function calc_age(): void {
    global $body;
    if (!empty($body)) {
        $body = do_shortcode($body);
    }
}

// Editor support: Admin -> Edit -> Insert Plugin Shortcode.
if (function_exists('register_editor_shortcode')) {
    register_editor_shortcode([
        'plugin' => 'Calc Age', 'plugin_slug' => 'calc-age', 'label' => 'Calculate age', 'tag' => 'calc-age',
        'description' => 'Print the age of a person from a date of birth.',
        'fields' => [
            ['name' => 'day', 'label' => 'Birth day', 'type' => 'number', 'required' => true, 'min' => '1', 'max' => '31', 'placeholder' => '15'],
            ['name' => 'month', 'label' => 'Birth month', 'type' => 'number', 'required' => true, 'min' => '1', 'max' => '12', 'placeholder' => '06'],
            ['name' => 'year', 'label' => 'Birth year', 'type' => 'number', 'required' => true, 'min' => '1900', 'max' => '2100', 'placeholder' => '1995'],
        ],
        'template' => '[calc-age day="{day}" month="{month}" year="{year}"]',
    ]);
}
