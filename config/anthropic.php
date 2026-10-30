<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic API Key
    |--------------------------------------------------------------------------
    |
    | Here you may specify your Anthropic API Key. This will be used to authenticate
    | with the Anthropic API - you can find your API key on your Anthropic
    | dashboard, at https://platform.claude.com/settings/keys.
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. By default, the client will time out after 30 seconds.
    */

    'request_timeout' => env('ANTHROPIC_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Beta Features
    |--------------------------------------------------------------------------
    |
    | Anthropic beta features opt in via the `anthropic-beta` header. Values
    | listed here are sent on every request made through the facade. They
    | combine with any `betas` array you pass on a specific call, so request
    | level betas still work on top of these defaults.
    |
    | Use ANTHROPIC_BETA as a comma separated list, or replace the default
    | with a plain array of strings in this file.
    |
    | Example: ANTHROPIC_BETA=files-api-2025-04-14,extended-cache-ttl-2025-04-11
    */

    'beta' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANTHROPIC_BETA', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The version sent in the `anthropic-version` header. Leave it empty to
    | use 2023-06-01. Version 2026-10-21 rejects some requests the older one
    | accepts, such as any that sets `temperature`, `top_p` or `top_k`, so
    | read the migration guide before you switch:
    | https://platform.claude.com/docs/en/api/migration-2026-10-21
    */

    'api_version' => env('ANTHROPIC_API_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | Point the client at a proxy or gateway in front of the API. Leave it
    | empty to use https://api.anthropic.com. /v1 is added to this URL, as
    | the official Anthropic SDKs do, so a gateway's documented
    | ANTHROPIC_BASE_URL works as it is. A URL that already ends in /v1 is
    | used as it is. A value exported in the shell that starts artisan, a
    | queue worker or `php artisan serve` wins over .env, and that host
    | receives your API key.
    */

    'base_url' => env('ANTHROPIC_BASE_URL'),
];
