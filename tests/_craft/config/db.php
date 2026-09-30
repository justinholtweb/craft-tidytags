<?php

use craft\helpers\App;

return [
    'dsn' => App::env('CRAFT_DB_DSN') ?: 'mysql:host=db;port=3306;dbname=db',
    'user' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];
