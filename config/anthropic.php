<?php

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
];
