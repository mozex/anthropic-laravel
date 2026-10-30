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
    'max_retries' => env('ANTHROPIC_MAX_RETRIES', 0),
    'middleware' => [],
    'fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANTHROPIC_FALLBACK_MODELS', ''))
    ))),
    'http' => [
        'middleware' => [],
        'laravel_client' => (bool) env('ANTHROPIC_HTTP_LARAVEL_CLIENT', false),
        'laravel_events' => (bool) env('ANTHROPIC_HTTP_LARAVEL_EVENTS', false),
        'telescope' => (bool) env('ANTHROPIC_HTTP_TELESCOPE', false),
        'nightwatch' => (bool) env('ANTHROPIC_HTTP_NIGHTWATCH', true),
    ],
    'context' => (bool) env('ANTHROPIC_CONTEXT', false),
    'headers' => [],
    'connections' => [],
    'beta' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANTHROPIC_BETA', ''))
    ))),
    'api_version' => env('ANTHROPIC_API_VERSION'),
    'base_url' => env('ANTHROPIC_BASE_URL'),
];
```

Only a credential is required: the API key, or an auth token in its place. The rest are optional: the workspace requests run in, the request timeout (30 seconds unless you change it) and the connect timeout, retries, refusal fallback models, Guzzle middleware, Laravel's HTTP client and its events, Telescope and Nightwatch recording, Laravel's Context, extra headers for every request, beta features to opt into, the API version, and a base URL for a proxy or gateway.

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
| `ANTHROPIC_MAX_RETRIES` | `0` | How many times a failed request is sent again |
| `ANTHROPIC_FALLBACK_MODELS` | None | Comma-separated models a refused request is sent to, in order (see [Messages](../usage/messages.md#falling-back-to-another-model)) |
| `ANTHROPIC_HTTP_LARAVEL_CLIENT` | `false` | Send through Laravel's HTTP client, so `Http::fake()` sees the calls |
| `ANTHROPIC_HTTP_LARAVEL_EVENTS` | `false` | Also dispatch Laravel's HTTP client events, which Telescope records |
| `ANTHROPIC_HTTP_TELESCOPE` | `false` | Record each call in Telescope, tagged with the model and the request ID |
| `ANTHROPIC_HTTP_NIGHTWATCH` | `true` | Attach Nightwatch's middleware when Nightwatch is installed |
| `ANTHROPIC_CONTEXT` | `false` | Add each answer's request ID and token counts to Laravel's Context |
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

## Retries

```env
ANTHROPIC_MAX_RETRIES=2
```

With retries on, the client sends a request again after a connection failure or a timeout, or a 408, 409, 429 or 5xx response (the 529 overload included), waiting as long as the API asks or backing off from half a second. Two is what the official SDKs use.

One call can then take up to `max_retries + 1` times `ANTHROPIC_REQUEST_TIMEOUT`, plus the waits between attempts, which last as long as the API's `retry-after` says. Size a queued job's `$timeout`, and the queue connection's `retry_after`, for that, or the worker hands the job to another process while the first is still waiting. A job that releases itself on a rate limit (see [Error Handling](./error-handling.md#retrying-failed-jobs)) does its own waiting, so it turns the client's retries off for its call with `Anthropic::withOptions(['max_retries' => 0])`. That works for a web request that can't wait, too.

## Guzzle middleware

`http.middleware` takes class names of Guzzle middleware, which the container resolves and pushes onto the client's handler stack. A class name keeps `config:cache` working, where a closure wouldn't:

```php
// config/anthropic.php
'http' => [
    'middleware' => [
        \App\Http\Anthropic\TagRequests::class,
    ],
],
```

```php
namespace App\Http\Anthropic;

use Psr\Http\Message\RequestInterface;

class TagRequests
{
    public function __invoke(callable $handler): callable
    {
        return fn (RequestInterface $request, array $options) => $handler($request->withHeader('X-App', config('app.name')), $options);
    }
}
```

Guzzle middleware sees every attempt, retries included.

## Events

Every HTTP attempt dispatches events in `Anthropic\Laravel\Events`: `RequestSending`, then `ResponseReceived` with the status, the decoded body, the `usage`, the rate-limit `meta` and `durationMs`. A failure dispatches `RequestFailed` (an error status, or a connection failure with a null status), and a 429 also dispatches `RateLimited`, whose `errorCode` is `enforced_spend_limit_reached` for the monthly spend cap. Every value is a scalar or an array, so a queued listener can take them, and no credential is in them: no header that holds one (the key, a token, a gateway key), and no MCP server's `authorization_token` from the body. Register listeners in a service provider's `boot()`:

```php
use Anthropic\Laravel\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (ResponseReceived $event) {
    if ($event->usage !== null) {
        Log::info('Claude call', [
            'tokens' => $event->usage['input_tokens'] + $event->usage['output_tokens'],
            'ms' => $event->durationMs,
        ]);
    }
});
```

A retried request dispatches its events once per attempt, and so does each fallback attempt. A streamed answer's `ResponseReceived` has no body or usage, since its events are still to be read, and only JSON bodies are decoded, never an upload. A failure without an answer has the `errorType` `connection_error`, `client_timeout_error` or `transport_error`. A listener that throws fails the call, even one that already went through, so keep listeners small or queued. `Anthropic::fake()` sends nothing, so it dispatches nothing; `Event::fake()` sees the events whenever it's set.

## Laravel's HTTP client and Telescope

Two switches put the calls where Laravel's own tools see them. Both are off by default, because they also put the calls in front of code that wasn't watching for them:

```env
ANTHROPIC_HTTP_LARAVEL_CLIENT=true
ANTHROPIC_HTTP_LARAVEL_EVENTS=true
```

With `laravel_client` on, `Http::fake()` answers the requests, `Http::assertSent()` sees them and `Http::preventStrayRequests()` applies, whenever they're set; an app's own catch-all `Http::fake()` then catches them too. With `laravel_events` on, each attempt also dispatches Laravel's `RequestSending`, then `ResponseReceived` or `ConnectionFailed`, always as a pair, which Telescope and Sentry record as outgoing requests; the credential headers are taken out first. A streamed answer gets them too, as with Laravel's own client, so a listener that reads its body drains the stream.

Telescope can also record the calls itself, tagged so you can filter them. With `ANTHROPIC_HTTP_TELESCOPE=true`, each attempt becomes a client request entry tagged `anthropic`, `model:claude-opus-5-5` and `req:req_018EeWyXxfu5pfWkrYcMdjWG`. Credentials are taken out, and Telescope's own `hideRequestHeaders()` and hidden parameters apply. Turn on this or `laravel_events`, not both, or every call shows up twice. Telescope stores the bodies, so your prompts land in its database. The options of Telescope's own client request watcher, such as `ignore_hosts`, don't apply to these entries.

With Laravel Nightwatch installed, its Guzzle middleware is attached on its own, so the calls appear in Nightwatch as outgoing requests. It stays off while `laravel_client` is on, because Nightwatch already records Laravel's HTTP client. Set `ANTHROPIC_HTTP_NIGHTWATCH=false` to leave the calls out; with `NIGHTWATCH_ENABLED=false` nothing is attached. A streamed call's duration there is the time to its headers, not to the end of the answer. Nightwatch keeps the URL's query string, so a gateway key passed there needs `Nightwatch::redactOutgoingRequests()`.

For OpenTelemetry spans with the GenAI attributes, add the client's `OpenTelemetryMiddleware` to `middleware` (see [Client middleware](#client-middleware) and the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration#opentelemetry)).

## Context

With `ANTHROPIC_CONTEXT=true`, every answer adds its request ID to Laravel's [Context](https://laravel.com/docs/13.x/context), and a non-streamed answer adds its token counts:

```php
use Illuminate\Support\Facades\Context;

Context::get('anthropic_request_ids');   // ['req_018EeWyXxfu5pfWkrYcMdjWG']
Context::get('anthropic_input_tokens');  // 10
Context::get('anthropic_output_tokens'); // 20
```

Context goes into every log line written after the call and travels with the jobs you dispatch, so a user's complaint leads you to the request IDs Anthropic support asks for. It changes your log output, so it's off by default. Each retry and fallback hop adds its own ID, and an error answer adds its ID too. The list keeps the last 20 IDs, so a job that makes thousands of calls doesn't grow its log lines without end. The token counts are the `input_tokens` and `output_tokens` of non-streamed Messages answers, cache tokens apart; a streamed answer adds none.

### Replying in the user's language

The client never changes a request on its own, so a reply in the user's language is a line in your `system` prompt. To keep the locale in queued jobs, carry it through Context, as Laravel's docs do:

```php
// AppServiceProvider::boot()
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;

Context::dehydrating(function (Repository $context) {
    $context->addHidden('locale', Config::get('app.locale'));
});

Context::hydrated(function (Repository $context) {
    if ($context->hasHidden('locale')) {
        Config::set('app.locale', $context->getHidden('locale'));
    }
});
```

```php
// in the job
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'system' => 'Reply in '.locale_get_display_language(app()->getLocale(), 'en').'.',
    'messages' => [['role' => 'user', 'content' => $question]],
]);
```

`locale_get_display_language()` comes from PHP's `intl` extension.

## Connections

The top-level keys configure the default connection. Name more under `connections`, each taking what it doesn't set from the top level, and reach them with `Anthropic::connection()`:

```php
// config/anthropic.php
'connections' => [
    'support' => [
        'api_key' => env('ANTHROPIC_SUPPORT_API_KEY'),
        'fallback_models' => ['claude-opus-4-8'],
    ],
],
```

```php
$reply = Anthropic::connection('support')->messages()->create([...]);
```

The merge is shallow: a connection's `beta` or `headers` replaces the top level's instead of adding to it. A connection that sets `api_key` or `auth_token` inherits neither, so it never sends two credentials, and one whose key is unset throws `ApiKeyIsMissing` naming it. `default`, `organization`, `enterprise` and `compliance` are the manager's own names, so `connections` can't use them.

A connection's client is built once, like a database connection; after changing its config at runtime, call `Anthropic::forgetConnection('support')` and the next call builds it again. Queue workers and Octane forget them between jobs and requests on their own. The default connection is the container's `ClientContract`, which stays as it was built: for a key that changes from one job to the next, use `withApiKey()` or a named connection.

For a key known only at runtime, such as a tenant's, `Anthropic::withApiKey($tenant->anthropic_key)` gives a client with the default connection's settings and that key. It's built on every call and never cached, so a key never outlives the job it was meant for.

`Anthropic::organization()`, `enterprise()` and `compliance()` use their own keys, which you add to the config when you need them, such as `'admin_key' => env('ANTHROPIC_ADMIN_KEY')` (`enterprise_key` and `compliance_key` work the same way). Each throws a clear error when its key is missing. They go where the default connection goes, with its base URL, headers and transport settings, but without its `workspace_id`, since these keys belong to the organization.

`Anthropic::fake()` answers every connection, `withApiKey()` included, and records which one each call used (a `withApiKey()` call counts as `default`):

```php
$fake = Anthropic::fake([CreateResponse::fake()]);

// ...

$fake->assertSentOn('support', Messages::class);
```

## Client middleware

`middleware` takes middleware for the client itself, which runs around every attempt with the PSR-7 request and the next handler. Class names keep the config cacheable, and a connection can carry its own list:

```php
// config/anthropic.php
'middleware' => [
    \Anthropic\Middleware\OpenTelemetryMiddleware::class, // needs open-telemetry/api
],
```

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

The options are `headers`, `query`, `betas`, `api_version`, `workspace_id`, `user_profile_id` and `max_retries`; the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration) describes each. Methods that take only an ID, such as `Anthropic::models()->retrieve()`, get their betas this way.

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

A `config/anthropic.php` published by an earlier release lacks the newer keys (`auth_token`, `workspace_id`, `connect_timeout`, `max_retries`, `middleware`, `fallback_models`, `http` and its `telescope` and `nightwatch` switches, `context`, `headers`, `connections`, `api_version`, `base_url`), and the package doesn't merge its defaults into your file, so their variables do nothing until you add the keys. Copy them from the config block above, or run `php artisan anthropic:install` again: it names the keys your published file is missing and adds the `.env` lines you don't have yet.

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

The facade's root is the connection manager, `Anthropic\Laravel\AnthropicManager`, and calls it doesn't know go to the default connection. The default client itself is a singleton in the container, so all of these are the same client:

```php
app(\Anthropic\Contracts\ClientContract::class);
app('anthropic');
\Anthropic\Laravel\Facades\Anthropic::connection();
```

Type-hint `ClientContract` for the default client, or `AnthropicManager` for every connection. The provider is deferred, so the client is only built the first time you use it.

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

The default client is Guzzle with the configured timeouts, retries, fallback models, events and `http` settings. If you need a different PSR-18 implementation, or Guzzle options the config doesn't cover, replace the client with `extend()` in a service provider's `register()` method:

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

The replacement is a new client, so it only has what you pass it: carry over the config values you rely on, like the API version and betas above (a blank version keeps the default), and `withWorkspaceId()`, `withAuthToken()`, `withMaxRetries((int) config('anthropic.max_retries'))`, `withMiddleware(new \Anthropic\Middleware\RefusalFallbackMiddleware(config('anthropic.fallback_models', [])))`, your `middleware` entries through `withMiddleware()` or `withBaseUrl((string) config('anthropic.base_url'))` when you use those keys, and `withMiddleware(new \Anthropic\Laravel\Middleware\DispatchesEvents(fn () => app('events')))` to keep the events, `new \Anthropic\Laravel\Middleware\AddsToContext(fn () => app(\Illuminate\Log\Context\Repository::class))` to keep Context and `new \Anthropic\Laravel\Middleware\RecordsInTelescope` to keep the Telescope entries. Push `app(\Laravel\Nightwatch\Core::class)->guzzleMiddleware()` onto your Guzzle stack to keep Nightwatch. For `laravel_client`, give your Guzzle client `'handler' => Http::buildHandlerStack()`, which fixes the fakes at the moment it's built. `http.middleware` reaches only the package's own Guzzle client, so push your middleware onto the handler stack of the client you build. The package's own client is built first and handed to the closure, so the package still needs a key or token (its own, or laravel/ai's), or `ApiKeyIsMissing` is thrown before your closure runs. The replacement is the default connection only: named connections and `withApiKey()` still build the package's own client.

Use `extend()`, not `singleton()` or `instance()`: the package's provider is deferred, and when it loads it registers its own binding over an earlier one, while an extender survives and runs on the package's client. `Anthropic::fake()` still replaces the result in tests. The factory's options are on the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration).
