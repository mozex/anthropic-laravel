# Upgrading

## To 1.11.0

This release requires `mozex/anthropic-php` 1.11, whose error statuses now throw subclasses of `ErrorException`, such as `OverloadedException` for a 529. A `catch (ErrorException $e)` still catches them. Code comparing the exact class (`get_class($e) === ErrorException::class`) no longer matches, error trackers that group by class name file these under the new names, and a `rate_limit_error` in the middle of a stream is now a `RateLimitException` (status 200, no `Retry-After`). Read its [upgrade notes](https://github.com/mozex/anthropic-php/blob/main/UPGRADING.md#to-1110) as well.

### The Models lifecycle Filter Now Applies

**Likelihood Of Impact: Low**

A list in a query, such as `Anthropic::models()->list(['lifecycle' => ['retired']])`, used to go out in a form the API accepts and ignores, so the filter did nothing. It's now sent as `lifecycle[]=retired`, and the results follow it.

### New Config Keys

**Likelihood Of Impact: Low**

The config has seven new keys: `auth_token` (`ANTHROPIC_AUTH_TOKEN`), `workspace_id` (`ANTHROPIC_WORKSPACE_ID`), `connect_timeout` (`ANTHROPIC_CONNECT_TIMEOUT`), `max_retries` (`ANTHROPIC_MAX_RETRIES`), `fallback_models` (`ANTHROPIC_FALLBACK_MODELS`), `http` (Guzzle middleware) and `headers`. A `config/anthropic.php` published earlier lacks them, and their variables do nothing until you copy the keys in; `php artisan anthropic:install` names the missing ones and adds the `.env` lines, commented out.

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
