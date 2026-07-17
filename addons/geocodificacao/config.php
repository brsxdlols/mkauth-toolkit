<?php
return array(
    'provider' => 'nominatim',
    'nominatim_url' => 'https://nominatim.openstreetmap.org/search',
    'user_agent' => 'MK-AUTH-Geocodificacao/1.0 (admin@localhost)',
    'cache_ttl' => 2592000,
    'min_interval_ms' => 1500,
    'cache_dir' => '/var/tmp/mkauth-geocodificacao',
    'db_host' => getenv('MKAUTH_DB_HOST') !== false ? getenv('MKAUTH_DB_HOST') : '127.0.0.1',
    'db_name' => getenv('MKAUTH_DB_NAME') !== false ? getenv('MKAUTH_DB_NAME') : 'mkradius',
    'db_user' => getenv('MKAUTH_DB_USER') !== false ? getenv('MKAUTH_DB_USER') : 'root',
    'db_pass' => getenv('MKAUTH_DB_PASS') !== false ? getenv('MKAUTH_DB_PASS') : 'vertrigo',
);
