<?php
// Zahltag – Konfigurationsdatei
// Kopiere diese Datei als config.php und passe die Werte an.
// Das Install-Script generiert config.php automatisch.

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'zahltag',
        'user'     => 'db_user',
        'password' => 'db_password',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'secret'   => 'RANDOM_32_BYTE_HEX_STRING_HERE',  // random_bytes(32) hex
        'debug'    => false,
        // Root deployment:      'https://example.com'
        // Subdirectory deployment: 'https://example.com/zahltag'
        // The path component is used as base_path for routing and asset URLs.
        // The install script sets .htaccess RewriteBase automatically from this value.
        'base_url' => 'https://example.com',
    ],
];
