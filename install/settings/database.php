<?php
/* settings/database.php */

return array(
    'mysql' => array(
        'dbdriver' => 'mysql',
        'username' => 'root',
        'password' => '',
        'dbname' => 'account',
        'prefix' => 'app'
    ),
    'tables' => array(
        'category' => 'category',
        'customer' => 'customer',
        'inventory' => 'inventory',
        'inventory_items' => 'inventory_items',
        'inventory_meta' => 'inventory_meta',
        'language' => 'language',
        'logs' => 'logs',
        'number' => 'number',
        'orders' => 'orders',
        'stock' => 'stock',
        'user' => 'user',
        'user_meta' => 'user_meta'
    )
);
