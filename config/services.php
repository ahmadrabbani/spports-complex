<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'payment_mode' => env('PAYMENT_MODE', 'mock'),

    'sap' => [
        'challan_url' => env('SAP_CHALLAN_PUBLIC_CREATE_URL', env('SAP_CHALLAN_URL', rtrim(env('SAP_BASE_URL', 'http://172.16.70.8:8080'), '/').env('SAP_CHALLAN_PATH', '/SAPVERIFICATION/Challan'))),
        'challan_fallback_url' => env('SAP_CHALLAN_INTERNAL_CREATE_URL'),
        'verification_url' => env('SAP_PAYMENT_VERIFICATION_URL', rtrim(env('SAP_BASE_URL', 'http://172.16.70.8:8080'), '/').env('SAP_VERIFICATION_PATH', '/SAPVERIFICATION/PaymentVerification')),
        'verification_parameter' => env('SAP_PAYMENT_VERIFICATION_PARAMETER', 'challan'),
        'connect_timeout' => env('SAP_CONNECT_TIMEOUT', 10),
        'timeout' => env('SAP_TIMEOUT', env('SAP_CHALLAN_TIMEOUT', 30)),
        'area' => env('SAP_CHALLAN_AREA', 1),
        'type' => env('SAP_CHALLAN_TYPE', 1),
    ],

    'bop_mpgs' => [
        'enabled' => env('BOP_MPGS_ENABLED', false),
        'environment' => env('BOP_MPGS_ENVIRONMENT', 'test'),
        'base_url' => env('BOP_MPGS_BASE_URL', 'https://test-gateway.mastercard.com'),
        'api_version' => env('BOP_MPGS_API_VERSION', 73),
        'merchant_id' => env('BOP_MPGS_MERCHANT_ID'),
        'api_password' => env('BOP_MPGS_API_PASSWORD'),
        'merchant_name' => env('BOP_MPGS_MERCHANT_NAME', 'LDA Sports Complex'),
        'webhook_key' => env('BOP_MPGS_WEBHOOK_KEY'),
        'timeout' => env('BOP_MPGS_TIMEOUT', 30),
    ],

];
