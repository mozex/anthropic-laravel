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
    | Auth Token
    |--------------------------------------------------------------------------
    |
    | A bearer token sent as `Authorization: Bearer <token>`, such as an access
    | token from Workload Identity Federation, in place of the API key. It's
    | used only when no API key is set. Claude Code and the official SDKs read
    | this name too, so a value exported in the shell that starts artisan, a
    | queue worker or `php artisan serve` wins over .env.
    */

    'auth_token' => env('ANTHROPIC_AUTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Workspace
    |--------------------------------------------------------------------------
    |
    | The ID of the workspace requests run in, sent as the
    | `anthropic-workspace-id` header. An API key that isn't scoped to one
    | workspace needs it; leave it empty otherwise.
    */

    'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),

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
    | Connect Timeout
    |--------------------------------------------------------------------------
    |
    | The maximum number of seconds to wait while connecting to the API. Leave
    | it empty to wait as long as the request timeout allows. Streamed requests
    | go through Guzzle's stream handler, which ignores it.
    */

    'connect_timeout' => env('ANTHROPIC_CONNECT_TIMEOUT'),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | How many times a failed request is sent again: connection failures and
    | timeouts, and the statuses the API marks as worth retrying (408, 409,
    | 429, 529 and other 5xx). The client waits as long as the API asks, or
    | backs off. Defaults to 0; the official SDKs use 2.
    */

    'max_retries' => env('ANTHROPIC_MAX_RETRIES', 0),

    /*
    |--------------------------------------------------------------------------
    | Refusal Fallback
    |--------------------------------------------------------------------------
    |
    | Model names to send a refused Messages request to, in order, with the
    | refusal's fallback credit so the retry isn't billed for new cache
    | writes. Each model must be one the refused model allows as a fallback,
    | such as claude-opus-4-8 for Claude Fable 5, and the list applies to
    | every model the app calls. Streamed requests are left alone, and a
    | request that sets `fallbacks` throws. Empty turns it off.
    |
    | Example: ANTHROPIC_FALLBACK_MODELS=claude-opus-5,claude-opus-4-8
    */

    'fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANTHROPIC_FALLBACK_MODELS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | HTTP Middleware
    |--------------------------------------------------------------------------
    |
    | Guzzle middleware pushed onto the client's handler stack, as class names
    | the container resolves to a Guzzle middleware callable. Class names keep
    | the config cacheable.
    */

    'http' => [
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra Headers
    |--------------------------------------------------------------------------
    |
    | Headers sent on every request, as name => value pairs, such as the ones
    | a proxy or gateway in front of the API expects. They're applied last, so
    | one here wins over a header the other keys set.
    */

    'headers' => [],

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
