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
    | Client Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware for the client itself (not Guzzle's), run around every HTTP
    | attempt: class names the container resolves to an invokable taking the
    | PSR-7 request and the next handler, such as the client's own
    | Anthropic\Middleware\OpenTelemetryMiddleware.
    */

    'middleware' => [],

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
    | HTTP Stack
    |--------------------------------------------------------------------------
    |
    | `middleware` takes Guzzle middleware pushed onto the client's handler
    | stack, as class names the container resolves to a Guzzle middleware
    | callable. Class names keep the config cacheable.
    |
    | `laravel_client` builds the stack from Laravel's HTTP client, so
    | Http::fake() and Http::assertSent() see the calls: an app's own
    | Http::fake() then catches them too, so it's off by default.
    |
    | `laravel_events` also dispatches Laravel's own HTTP client events
    | (ResponseReceived, ConnectionFailed), which Telescope records; other
    | listeners of those events see the calls too, so it's off by default.
    |
    | `telescope` records each call in Telescope itself, tagged with the
    | model and the request ID. Turn on this or `laravel_events`, not both,
    | or every call shows up twice.
    |
    | `nightwatch` attaches Nightwatch's Guzzle middleware when Nightwatch is
    | installed and `laravel_client` is off (Nightwatch already records
    | Laravel's client). Set it to false to leave the calls out.
    */

    'http' => [
        'middleware' => [],
        'laravel_client' => (bool) env('ANTHROPIC_HTTP_LARAVEL_CLIENT', false),
        'laravel_events' => (bool) env('ANTHROPIC_HTTP_LARAVEL_EVENTS', false),
        'telescope' => (bool) env('ANTHROPIC_HTTP_TELESCOPE', false),
        'nightwatch' => (bool) env('ANTHROPIC_HTTP_NIGHTWATCH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | Adds each answer's request ID (`anthropic_request_ids`) and a
    | non-streamed answer's token counts (`anthropic_input_tokens`,
    | `anthropic_output_tokens`) to Laravel's Context, so every log line and
    | queued job after a call carries them. It changes your log output, so
    | it's off by default.
    */

    'context' => (bool) env('ANTHROPIC_CONTEXT', false),

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
    | Connections
    |--------------------------------------------------------------------------
    |
    | The other keys in this file configure the default connection. Name
    | more here, each taking what it doesn't set from those keys (a shallow
    | merge: a connection's `beta` replaces the default's, and one that sets
    | a key or token inherits neither), and reach them with
    | Anthropic::connection('name'). The names default, organization,
    | enterprise and compliance belong to the manager.
    |
    | 'connections' => [
    |     'support' => ['api_key' => env('ANTHROPIC_SUPPORT_API_KEY')],
    | ],
    */

    'connections' => [],

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
