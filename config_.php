<?php
/**
 * NexaCMS Default Uninstalled Config Template (config_.php)
 */
$config = [
    'installed'  => false,
    'is_subsite' => false,
    'database'   => [
        'type'                  => 'csv',
        'location'              => 'admin/database',
        'name'                  => 'db',
        'host'                  => '127.0.0.1',
        'port'                  => '',
        'user'                  => 'root',
        'pass'                  => '',
        'file'                  => '',
        'auto_migrate_from_csv' => true,
    ],
    'abs'        => __DIR__,
    'auth_key'   => '',
    'secret_key' => '',
];
