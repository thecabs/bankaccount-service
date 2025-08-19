<?php

return [
    'realm_url' => env('KEYCLOAK_REALM_URL'),
    'client_id' => env('KEYCLOAK_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
    'public_key' => env('KEYCLOAK_PUBLIC_KEY'),
    'cache_openid' => env('KEYCLOAK_CACHE_OPENID', true),
];

