<?php
// Copy to config.php and enter the database credentials from ALL-INKL KAS.
return [
    'dsn' => 'mysql:host=localhost;dbname=DATABASE;charset=utf8mb4',
    'username' => 'DATABASE_USER',
    'password' => 'CHANGE_ME',
    // Generate a fresh secret (for example: openssl rand -hex 32).
    // It is required only for the first setup and must never be committed.
    'setup_key' => 'REPLACE_WITH_A_RANDOM_64_CHARACTER_SECRET',
];
