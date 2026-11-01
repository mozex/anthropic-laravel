# Upgrading

## To 1.12.0

This release requires `mozex/anthropic-php` 1.12. Read its [upgrade notes](https://github.com/mozex/anthropic-php/blob/main/UPGRADING.md#to-1120) as well.

### A Connection Manager Behind The Facade

**Likelihood Of Impact: Low**

`Anthropic::getFacadeRoot()` now returns `Anthropic\Laravel\AnthropicManager`, which forwards the calls it doesn't know to the default client, so `Anthropic::messages()` and the rest work as before. Code that read the root expecting the client should type-hint `Anthropic\Contracts\ClientContract` instead; `app('anthropic')` and `ClientContract` still give the client.

A new eager provider, `Anthropic\Laravel\AnthropicServiceProvider`, is auto-discovered. An app that lists its providers by hand (with `dont-discover`) adds it beside `Anthropic\Laravel\ServiceProvider`.

The config gains `connections` and `middleware`; `php artisan anthropic:install` names them when an older published file lacks them. An app with only laravel/ai's Anthropic key configured now gets a working client from it.

### Config Values Of The Wrong Type Throw

**Likelihood Of Impact: Low**

A config value of the wrong type throws `Anthropic\Exceptions\InvalidArgumentException` when the client is built, naming the key: an `api_version` or `base_url` that isn't a string (1.10.0 skipped it; `ANTHROPIC_BASE_URL=true` gives the boolean `true`), a `max_retries` that isn't a whole number of 0 or more, or a value in `headers` that isn't a string, number or boolean. A `beta` entry that isn't a string is still skipped, but a `beta` written by hand as one string, such as `env('ANTHROPIC_BETA')`, is now sent, split at its commas, where 1.10.0 ignored it.

### Nightwatch Records The Calls

**Likelihood Of Impact: Low**

With Laravel Nightwatch installed and enabled, its Guzzle middleware is now attached to the client, so the API calls show up in Nightwatch as outgoing requests. Set `ANTHROPIC_HTTP_NIGHTWATCH=false` to leave them out; a `config/anthropic.php` published earlier needs an `http` array with `'nightwatch' => (bool) env('ANTHROPIC_HTTP_NIGHTWATCH', true)` in it first (or the whole `http` block from the package's config).

### New Config Keys

**Likelihood Of Impact: Low**

The config has new keys: `auth_token` (`ANTHROPIC_AUTH_TOKEN`), `workspace_id` (`ANTHROPIC_WORKSPACE_ID`), `connect_timeout` (`ANTHROPIC_CONNECT_TIMEOUT`), `max_retries` (`ANTHROPIC_MAX_RETRIES`), `fallback_models` (`ANTHROPIC_FALLBACK_MODELS`), `context` (`ANTHROPIC_CONTEXT`), which adds each answer's request ID and token counts to Laravel's Context, `headers`, and `http`. The `http` array holds Guzzle middleware and four switches: `laravel_client` (`ANTHROPIC_HTTP_LARAVEL_CLIENT`), `laravel_events` (`ANTHROPIC_HTTP_LARAVEL_EVENTS`), `telescope` (`ANTHROPIC_HTTP_TELESCOPE`), which records each call in Telescope with tags, and `nightwatch` (`ANTHROPIC_HTTP_NIGHTWATCH`, on by default). A `config/anthropic.php` published earlier lacks them, and their variables do nothing until you copy the keys in; `php artisan anthropic:install` names the missing ones and adds the `.env` lines, commented out.

### Error Statuses Throw Subclasses Of ErrorException

**Likelihood Of Impact: Low**

`mozex/anthropic-php` 1.12 throws a subclass of `ErrorException` for each error status, such as `OverloadedException` for a 529, and the facade's calls do too. A `catch (ErrorException $e)` still catches them. Code comparing the exact class (`get_class($e) === ErrorException::class`) no longer matches, error trackers that group by class name file these under the new names, and a `rate_limit_error` in the middle of a stream is now a `RateLimitException` (status 200, no `Retry-After`).

### The Models lifecycle Filter Now Applies

**Likelihood Of Impact: Low**

A list in a query, such as `Anthropic::models()->list(['lifecycle' => ['retired']])`, used to go out in a form the API accepts and ignores, so the filter did nothing. It's now sent as `lifecycle[]=retired`, and the results follow it.

### Events For Every Request

**Likelihood Of Impact: Low**

Every HTTP attempt now dispatches `Anthropic\Laravel\Events\RequestSending`, `ResponseReceived`, and on a failure `RequestFailed` (with `RateLimited` on a 429). A listener for those classes, or a wildcard listener, now sees them. Nothing is built or dispatched while no listener waits.

## To 1.10.0

This release requires `mozex/anthropic-php` 1.10, whose changes reach you through the facade too: every error status throws `ErrorException`, `toArray()` returns every field the API sent, and a few response fields became nullable. Read its [upgrade notes](https://github.com/mozex/anthropic-php/blob/main/UPGRADING.md#to-1100) as well.

### Anthropic::fake() Also Replaces Injected Clients

**Likelihood Of Impact: Medium**

`Anthropic::fake()` used to swap only the facade, so a class that had the client injected through its constructor still got the real one and called the API during tests. The fake is now bound as `Anthropic\Contracts\ClientContract` too, so injected code gets it.

A dependency typed with the class, `Anthropic\Client`, can't take the fake: resolving it after `Anthropic::fake()` throws a `TypeError`. Type-hint the contract instead:

```php
use Anthropic\Contracts\ClientContract;

public function __construct(private ClientContract $client) {}
```

### New api_version And base_url Config Keys

**Likelihood Of Impact: Low**

The config has two new keys: `api_version` (`ANTHROPIC_API_VERSION`) and `base_url` (`ANTHROPIC_BASE_URL`). The package doesn't merge its defaults into a published config file, so a `config/anthropic.php` published by an earlier release lacks them, and setting the two variables does nothing until you add the keys:

```php
'api_version' => env('ANTHROPIC_API_VERSION'),
'base_url' => env('ANTHROPIC_BASE_URL'),
```

Running `php artisan anthropic:install` again names the keys your published file is missing. It also adds each `.env` line a file lacks, the two new ones commented out, since other tools read the same variable names: Prism reads `ANTHROPIC_API_VERSION`, and Claude Code and the official SDKs read `ANTHROPIC_BASE_URL`.

### Text Completions Is Deprecated

**Likelihood Of Impact: Low**

The API now rejects Text Completions requests on current models, and `mozex/anthropic-php` marks the resource deprecated. `Anthropic::completions()` keeps working for as long as the API serves your model. PHPStan's deprecation rules flag the `create()` and `createStreamed()` calls chained on it, but the facade's `completions()` itself can't carry the deprecation, so an IDE may not mark it; search for `Anthropic::completions(` and move those calls to the Messages API.

### Publishing The Config By Tag

**Likelihood Of Impact: None (Backwards Compatible)**

`php artisan vendor:publish --tag=anthropic-config` publishes the config file. The `--tag=config` command the docs used to show never published anything; publishing by provider keeps working.
