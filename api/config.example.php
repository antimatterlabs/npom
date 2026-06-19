<?php

return [
    'app' => [
        'debug' => false,
        'hash_salt' => '',
    ],

    'database' => [
        // Use sqlite locally. On Cloudways, prefer a path outside public_html if possible.
        'driver' => 'sqlite',
        'sqlite_path' => dirname(__DIR__) . '/storage/npom.sqlite',

        // Optional MySQL fallback.
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],

    'mailgun' => [
        'enabled' => false,
        'endpoint' => 'https://api.mailgun.net/v3',
        // For EU domains, use: https://api.eu.mailgun.net/v3
        'api_key' => '',
        'domain' => '',
        'from' => 'North Preston Outreach Ministry <no-reply@example.com>',
        'notify_to' => 'brandon@antimatterlabs.ca',
        'mailing_list' => '',
        'subscribe_to_list' => false,
        'notify_on_subscribe' => false,
    ],

    'admin' => [
        'admin_pass' => '',
        'password_hash' => '',
        'session_name' => 'npom_admin',
    ],
];
