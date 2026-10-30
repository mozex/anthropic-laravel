---
title: Configuration
weight: 1
---

Configuration happens through `config/anthropic.php` and environment variables. Run `php artisan anthropic:install` to publish the config file and add `ANTHROPIC_API_KEY=` to your `.env` and `.env.example`, with the optional variables below it commented out.

## Config file

After running the install command, you'll have `config/anthropic.php`:

```php
return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'auth_token' => env('ANTHROPIC_AUTH_TOKEN'),
    'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
    'request_timeout' => env('ANTHROPIC_REQUEST_TIMEOUT', 30),
    'connect_timeout' => env('ANTHROPIC_CONNECT_TIMEOUT'),
    'headers' => [],
    'beta' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANTHROPIC_BETA', ''))
    ))),
    'api_version' => env('ANTHROPIC_API_VERSION'),
    'base_url' => env('ANTHROPIC_BASE_URL'),
];
```

Only a credential is required: the API key, or an auth token in its place. The rest are optional: the workspace requests run in, the request timeout (30 seconds unless you change it) and the connect timeout, extra headers for every request, beta features to opt into, the API version, and a base URL for a proxy or gateway.

## Environment variables

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_REQUEST_TIMEOUT=30
ANTHROPIC_BETA=files-api-2025-04-14,extended-cache-ttl-2025-04-11
```

| Variable | Default | Purpose |
|----------|---------|---------|
| `ANTHROPIC_API_KEY` | None (required without a token) | Your API key from the [Anthropic Console](https://platform.claude.com/settings/keys) |
| `ANTHROPIC_AUTH_TOKEN` | None | A bearer token, such as a Workload Identity Federation access token, sent as `Authorization: Bearer` when no API key is set |
| `ANTHROPIC_WORKSPACE_ID` | None | The `anthropic-workspace-id` header, which an API key not scoped to one workspace needs |
| `ANTHROPIC_REQUEST_TIMEOUT` | `30` | HTTP request timeout in seconds |
| `ANTHROPIC_CONNECT_TIMEOUT` | None | Seconds to wait while connecting (streamed requests ignore it) |
| `ANTHROPIC_BETA` | None | Comma-separated list of beta feature headers to send on every request |
| `ANTHROPIC_API_VERSION` | `2023-06-01` | The `anthropic-version` header sent on every request |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | A proxy or gateway in front of the API, without `/v1` |

If neither `ANTHROPIC_API_KEY` nor `ANTHROPIC_AUTH_TOKEN` is set when the client is resolved, the service provider throws `Anthropic\Laravel\Exceptions\ApiKeyIsMissing`. The client sends one credential, as the official SDKs do: the key when one is set, the token otherwise. Claude Code and the SDKs read `ANTHROPIC_AUTH_TOKEN` too, so a token exported in the shell that starts artisan or a queue worker reaches the app when its `.env` has no key. `headers` has no environment variable: set it in the config file, as `['X-Gateway-Key' => env('GATEWAY_KEY')]` for example; those headers are applied last.

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

## Per-request options

`Anthropic::withOptions()` returns a client whose requests carry extra settings, leaving the app-wide client as it is. It's the way to run one call in another workspace, add a beta for one feature, or tag requests for your gateway:

```php
use Anthropic\Laravel\Facades\Anthropic;

$client = Anthropic::withOptions([
    'workspace_id' => $team->anthropic_workspace_id,
    'betas' => ['server-side-fallback-2026-07-01'],
    'headers' => ['X-Team' => (string) $team->id],
]);

$response = $client->messages()->create([...]);
```

The options are `headers`, `query`, `betas`, `api_version`, `workspace_id` and `user_profile_id`; the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration) describes each. Methods that take only an ID, such as `Anthropic::models()->retrieve()`, get their betas this way.

## API version

```env
ANTHROPIC_API_VERSION=2026-10-21
```

Requests go out with `anthropic-version: 2023-06-01` unless you set this. Version `2026-10-21` rejects some requests the older one accepts: `temperature`, `top_p` and `top_k` return a 400 on every model, and so do `document` blocks placed directly in an assistant message. Read the [migration guide](https://platform.claude.com/docs/en/api/migration-2026-10-21) before you switch.

Other packages read the same variable. Prism does, so setting it in `.env` switches Prism's Anthropic requests to that version too. That's why the install command adds it commented out: an empty `ANTHROPIC_API_VERSION=` line would hand Prism an empty version.

## Base URL

```env
ANTHROPIC_BASE_URL=https://gateway.example.com/anthropic
```

Point the client at a proxy or an AI gateway. The value means the same as it does to the official Anthropic SDKs and Claude Code: the URL without the API's `/v1`. The package adds `/v1` unless the URL already ends in it, so a gateway's documented `ANTHROPIC_BASE_URL` works as it is. The example above sends requests to `https://gateway.example.com/anthropic/v1/messages`.

A few things to know before you set it:

- Claude Code and the official SDKs read the same variable, so a value exported in your shell wins over `.env` for `php artisan` commands, queue workers and `php artisan serve`, and that host receives your API key with every request. Run `php artisan config:cache` in that shell and the value is baked into the cached config, so web requests use it too.
- An `http://` URL sends the key in clear text. Use `https://` for anything outside your own machine.
- With a cached config, run `php artisan config:clear` (or `config:cache` again) after changing either variable.

## Upgrading an older config file

A `config/anthropic.php` published by an earlier release lacks the newer keys (`auth_token`, `workspace_id`, `connect_timeout`, `headers`, `api_version`, `base_url`), and the package doesn't merge its defaults into your file, so their variables do nothing until you add the keys. Copy them from the config block above, or run `php artisan anthropic:install` again: it names the keys your published file is missing and adds the `.env` lines you don't have yet.

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
    'model' => 'claude-opus-5-5',
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
    'model' => 'claude-opus-5-5',
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

The install command publishes the config as part of its flow. To publish it yourself, run:

```bash
php artisan vendor:publish --tag=anthropic-config
```

It leaves an existing `config/anthropic.php` alone unless you add `--force`, which overwrites your changes. To bring an older file up to date, see [Upgrading an older config file](#upgrading-an-older-config-file).

## Using a custom HTTP client

The default client is Guzzle with the configured timeout. If you need different HTTP client behavior (custom middleware, retries, a different PSR-18 implementation), replace the client with `extend()` in a service provider's `register()` method:

```php
use Anthropic;
use Anthropic\Contracts\ClientContract;
use GuzzleHttp\Client as Guzzle;

public function register(): void
{
    $this->app->extend(ClientContract::class, fn () => Anthropic::factory()
        ->withApiKey(config('anthropic.api_key'))
        ->withApiVersion((string) config('anthropic.api_version'))
        ->withHttpHeader('anthropic-beta', implode(',', config('anthropic.beta', [])))
        ->withHttpClient(new Guzzle(['timeout' => 120, 'connect_timeout' => 5]))
        ->make());
}
```

The replacement is a new client, so it only has what you pass it: carry over the config values you rely on, like the API version and betas above (a blank version keeps the default), and `withWorkspaceId()`, `withAuthToken()` or `withBaseUrl((string) config('anthropic.base_url'))` when you use those keys. The package's own client is built first and handed to the closure, so `ANTHROPIC_API_KEY` or `ANTHROPIC_AUTH_TOKEN` still has to be set, or `ApiKeyIsMissing` is thrown before your closure runs.

Use `extend()`, not `singleton()` or `instance()`: the package's provider is deferred, and when it loads it registers its own binding over an earlier one, while an extender survives and runs on the package's client. `Anthropic::fake()` still replaces the result in tests. The factory's options are on the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration).
