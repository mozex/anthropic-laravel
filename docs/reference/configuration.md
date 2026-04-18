---
title: Configuration
weight: 1
---

Configuration happens through `config/anthropic.php` and environment variables. Run `php artisan anthropic:install` to publish the config file and add `ANTHROPIC_API_KEY=` to your `.env` automatically.

## Config file

After running the install command, you'll have `config/anthropic.php`:

```php
return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'request_timeout' => env('ANTHROPIC_REQUEST_TIMEOUT', 30),
    'beta' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANTHROPIC_BETA', ''))
    ))),
];
```

Three options. API key, timeout, and a list of Anthropic beta features to opt into.

## Environment variables

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_REQUEST_TIMEOUT=30
ANTHROPIC_BETA=files-api-2025-04-14,extended-cache-ttl-2025-04-11
```

| Variable | Default | Purpose |
|----------|---------|---------|
| `ANTHROPIC_API_KEY` | None (required) | Your API key from the [Anthropic Console](https://platform.claude.com/settings/keys) |
| `ANTHROPIC_REQUEST_TIMEOUT` | `30` | HTTP request timeout in seconds |
| `ANTHROPIC_BETA` | None | Comma-separated list of beta feature headers to send on every request |

If `ANTHROPIC_API_KEY` is missing or not a string when the client is resolved, the service provider throws `Anthropic\Laravel\Exceptions\ApiKeyIsMissing`.

## Timeout configuration

The default 30-second timeout is fine for typical message requests. For longer operations, raise it:

```env
ANTHROPIC_REQUEST_TIMEOUT=120
```

You'll want a longer timeout when:

- Using [extended thinking](../usage/thinking.md) with high effort (can take 60+ seconds)
- Running [code execution](../usage/server-tools.md) (sandboxed runs add latency)
- Sending large context windows (200k+ tokens)
- Getting non-streamed responses where you want the full message back

For streamed requests, the timeout applies to the initial connection. After that, data flows chunk-by-chunk.

## Beta features

Anthropic ships new capabilities behind beta feature flags first. You opt in by sending an `anthropic-beta` header. The Laravel package gives you two ways to enable them: globally for every request from the container's singleton, or per call for one specific request.

### Global via config

Set `ANTHROPIC_BETA` in `.env` as a comma-separated list. The service provider picks it up and applies it to the underlying client as a default header:

```env
ANTHROPIC_BETA=files-api-2025-04-14,extended-cache-ttl-2025-04-11
```

That's equivalent to replacing the config entry with a plain PHP array if you prefer keeping betas in version control rather than env:

```php
// config/anthropic.php
'beta' => [
    'files-api-2025-04-14',
    'extended-cache-ttl-2025-04-11',
],
```

Either way, every call through `Anthropic::messages()`, `Anthropic::batches()`, and so on sends the header without you thinking about it.

### Per request via `betas`

Pass a `betas` array inside the parameters you hand to any resource method. The SDK pulls it out before serialization and turns it into a one-request `anthropic-beta` header. The key never appears in the JSON body or the query string.

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
    'betas' => ['interleaved-thinking-2025-05-14'],
]);
```

This is what you want when one piece of code needs a beta the rest of your app doesn't, or when you're experimenting with a new feature before committing it to the global config.

### Combining both

Global and per-request stack. If your config has `files-api-2025-04-14` and a specific call passes `betas: ['extended-cache-ttl-2025-04-11']`, that one request sends both values, de-duplicated.

A typical pattern: keep stable betas your team has adopted in config, opt into experimental ones per call.

```php
// config/anthropic.php
'beta' => ['files-api-2025-04-14'], // always on

// Specific call adds another beta for this request only
Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 1024,
    'messages' => [...],
    'betas' => ['interleaved-thinking-2025-05-14'],
]);
// Sends: anthropic-beta: files-api-2025-04-14,interleaved-thinking-2025-05-14
```

Some resources require a specific beta header to work at all. The SDK auto-injects those for you, so you never have to type the version string for the resource you're already calling. Config and per-request betas merge with the auto-injected value.

For how beta headers work, multi-beta syntax, and version naming conventions, see the [Beta headers reference](https://platform.claude.com/docs/en/api/beta-headers) on the Anthropic docs. Individual beta feature names are documented on the page for the feature they gate (Files API, extended cache TTL, interleaved thinking, and so on).

## The service container binding

The service provider registers the client as a singleton in the container. That means the same client instance is reused across your entire request lifecycle:

```php
// All three of these resolve the same client
app(\Anthropic\Contracts\ClientContract::class);
app('anthropic');
\Anthropic\Laravel\Facades\Anthropic::messages();
```

The provider is deferred, so the client only gets instantiated the first time you actually use it.

## Config caching

If you use `php artisan config:cache` in production (highly recommended), the client picks up cached config values just like any other Laravel package. No special handling needed.

If you change the API key or timeout, run `php artisan config:clear` to pick up the new values.

## Publishing the config file manually

The install command publishes the config as part of its flow. If you need to publish it again (e.g., after an update), run:

```bash
php artisan vendor:publish --tag=config --provider="Anthropic\Laravel\ServiceProvider"
```

## Using a custom HTTP client

The default client is Guzzle with the configured timeout. If you need different HTTP client behavior (custom middleware, retries, a different PSR-18 implementation), bind the `Anthropic\Contracts\ClientContract` yourself in a service provider. Full examples are on the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration).
