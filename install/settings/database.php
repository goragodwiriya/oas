<?php
/* settings/database.php */

return [
    'mysql' => [
        'dbdriver' => 'mysql',
        'username' => 'root',
        'password' => '',
        'dbname' => 'now_inventory',
        'prefix' => 'app'
    ],
    'tables' => [
        'category' => 'category',
        'language' => 'language',
        'logs' => 'logs',
        'user' => 'user',
        'user_meta' => 'user_meta'
    ]
];
