<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */
 'ceiling' => [
        'base_url' => env('USER_CEILING_URL', 'http://127.0.0.1:8081'),
                'timeout'  => (int) env('HTTP_TIMEOUT', 20),
 // ex. http://127.0.0.1:8001
    ],
    'user_management' => [
        'base_url' => env('USER_MANAGEMENT_URL', 'http://127.0.0.1:8001'), 
                'timeout'  => (int) env('HTTP_TIMEOUT', 20),
// ex. http://localhost:8081/api
    ],
     // OAuth pour appels S2S
        'oauth' => [
            'token_url'     => rtrim(env('KEYCLOAK_BASE_URL'), '/').'/realms/'.env('KEYCLOAK_REALM').'/protocol/openid-connect/token',
            'client_id'     => env('SVC_BANKACCOUNT_ID'),
            'client_secret' => env('SVC_BANKACCOUNT_SECRET'),
        ],

    'keycloak' => [
        'base_url'      => rtrim(env('KEYCLOAK_BASE_URL', 'http://10.10.1.161:30976'), '/'),
        'realm'         => env('KEYCLOAK_REALM', 'sara-realm'),
        'client_id'     => env('KEYCLOAK_CLIENT_ID', ''),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET', ''),
    ],

    // ===== autres services exemples (inchangés) =====

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

     'audit' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/audit.log'),
    'days'   => 14,
    'level'  => env('LOG_LEVEL', 'info'),
    'formatter' => Monolog\Formatter\JsonFormatter::class,
  ],

];
