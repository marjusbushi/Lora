<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database export
    |--------------------------------------------------------------------------
    |
    | Credentials are read from the active Laravel database connection and are
    | passed to mysqldump through MYSQL_PWD, never as command-line arguments.
    |
    */
    'mysql_dump_binary' => env('LORA_MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_dump_timeout_seconds' => (int) env('LORA_MYSQLDUMP_TIMEOUT_SECONDS', 900),
];
