<?php

use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', StreamHandler::class),
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        // ====== Canal AUDIT JSON (avec masquage PII) ======
        // 'audit' => [
        //     'driver'    => 'single',
        //     'path'      => storage_path('logs/audit.log'),
        //     'level'     => env('LOG_AUDIT_LEVEL', 'info'),
        //     'tap'       => [App\Logging\MaskSensitiveData::class], // applique notre sanitizer
        //     'formatter' => JsonFormatter::class,
        //     // 'days'   => 30, // si tu veux passer en daily, crée un canal daily dédié
        // ],
        'audit' => [
    'driver'     => 'daily',
    'path'       => storage_path('logs/audit.log'),
    'level'      => env('LOG_LEVEL', 'info'),
    'days'       => 30,
    'tap'        => [App\Logging\MaskSensitiveData::class],
    'processors' => [\Monolog\Processor\PsrLogMessageProcessor::class],
],


        // Null channel
        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],

];
