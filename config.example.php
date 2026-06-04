<?php

declare(strict_types=1);

return [
    'app_name' => 'VMail Manager',
    'db' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/data/vmail-test.sqlite',

        'host' => '127.0.0.1',
        'port' => 3307,
        'name' => 'vmail',
        'user' => 'vmail',
        'password' => '',
    ],
    'admins' => [
        'admin' => [
            // Password for the SQLite test environment: Admin123
            'password_hash' => '$2y$12$fHLAIdDN./DO.9aek4EN6uGRYYngokPLMCUURFte7zEG64RJ7sDjq',
            'domains' => ['autismus-asperger.test', 'silberschneider.test', 'kobaude.test'],
        ],
        'domainadmin' => [
            // Password for the SQLite test environment: Domain123
            'password_hash' => '$2y$12$GAVNyUaS0GMGmwV0RIcRReFaO23F3EUV2eHGNXLLvqeGGDNyinDLa',
            'domains' => ['autismus-asperger.test'],
        ],
    ],
    'password_policy' => [
        'min_length' => 8,
        'require_lowercase' => true,
        'require_uppercase' => true,
        'require_number' => true,
    ],
    'maildir_path_template' => '',
];
