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
];
```

Two options. That's it.

## Environment variables

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_REQUEST_TIMEOUT=30
```

| Variable | Default | Purpose |
|----------|---------|---------|
| `ANTHROPIC_API_KEY` | None (required) | Your API key from the [Anthropic Console](https://platform.claude.com/settings/keys) |
| `ANTHROPIC_REQUEST_TIMEOUT` | `30` | HTTP request timeout in seconds |

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
