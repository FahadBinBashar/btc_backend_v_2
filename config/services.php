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

    'metamap' => [
        'base_url' => env('METAMAP_BASE_URL', 'https://api.getmati.com'),
        'client_id' => env('METAMAP_CLIENT_ID'),
        'client_secret' => env('METAMAP_CLIENT_SECRET'),
        'citizen_flow_id' => env('METAMAP_CITIZEN_FLOW_ID'),
        'non_citizen_flow_id' => env('METAMAP_NON_CITIZEN_FLOW_ID'),
        'webhook_secret' => env('METAMAP_WEBHOOK_SECRET'),
    ],

    'dpo' => [
        'paygate_url' => env('DPO_PAYGATE_URL'),
        'secret' => env('DPO_SECRET'),
        'id' => env('DPO_ID'),
    ],

    'c1' => [
        'security_ip' => env('C1_SECURITY_IP', env('c1_host')),
        'security_user' => env('C1_SECURITY_USER', env('c1_username')),
        'security_password' => env('C1_SECURITY_PASSWORD', env('c1_password')),
        'realm' => env('C1_REALM', env('c1_realm', 'sapi')),
        'billing_ip' => env('C1_BILLING_IP', env('c1_billing_host')),
        'billing_user' => env('C1_BILLING_USER', env('c1_username')),
    ],

    'smega' => [
        'check_ip' => env('SMEGA_CHECK_IP', env('smega_host')),
        'check_user' => env('SMEGA_CHECK_USER', env('smega_login')),
        'check_password' => env('SMEGA_CHECK_PASSWORD', env('smega_password')),
        'aml_check_ip' => env('SMEGA_AML_CHECK_IP'),
        'registration_ip' => env('SMEGA_REGISTRATION_IP'),
        'registration_user' => env('SMEGA_REGISTRATION_USER', env('smega_login')),
    ],

    'bocra' => [
        'sandbox_url' => env('BOCRA_SANDBOX_URL', env('bocra_host')),
        'api_key' => env('BOCRA_API_KEY', env('bocra_api_key')),
    ],

    'middleware' => [
        'log_url' => env('MIDDLEWARE_LOG_URL', env('middleware_host')),
    ],

];
