# anthropic-laravel

## Project

`mozex/anthropic-laravel` — A Laravel integration package wrapping `mozex/anthropic-php` (framework-agnostic Anthropic API client). Exposes the client via a service provider, facade, and Artisan install command. Namespace: `Anthropic\Laravel\`. Requires PHP 8.2+, Laravel 12 or 13.

## Commands

```bash
composer lint             # Fix code style with Pint
composer test             # Run ALL checks: lint, types, type coverage, unit tests
composer test:lint        # Check code style without fixing
composer test:types       # Run PHPStan (level max)
composer test:type-coverage  # Verify 100% type coverage
composer test:unit        # Run Pest unit tests
```

Run a single test file:
```bash
./vendor/bin/pest tests/Facades/AnthropicTest.php
```

Run tests by name:
```bash
./vendor/bin/pest --filter="test name substring"
```

## Architecture

Small package with 6 source files in `src/`:

- **`ServiceProvider.php`** — `DeferrableProvider` that registers `ClientContract` as a lazy singleton, aliased to `'anthropic'` and `Client::class`. Auto-discovered via `extra.laravel.providers` in `composer.json`. Publishes `config/anthropic.php` under the provider and the `anthropic-config` tag.
- **`Facades/Anthropic.php`** — Facade resolving `'anthropic'`. Has `fake(array $responses)` for testing: it loads the deferred provider first (in a container that still defers it, `loadDeferredProviders()`, which every `artisan()` call runs, would otherwise bind the real client over the fake), binds the `AnthropicFake` as `ClientContract`, then swaps the root. Code that type-hints `Anthropic\Client` can't take the fake; the docs say to type-hint `ClientContract`.
- **`Testing/AnthropicFake.php`** — Thin subclass of `Anthropic\Testing\ClientFake`. Provides `assertSent()`, `assertNotSent()`, `assertNothingSent()`.
- **`Exceptions/ApiKeyIsMissing.php`** — Thrown when `anthropic.api_key` config is missing or not a string and no `anthropic.auth_token` is set.
- **`Commands/InstallCommand.php`** — `php artisan anthropic:install`. Copies config, appends env vars to `.env`/`.env.example`, then asks for a GitHub star (default yes). Uses Termwind for console output via `Support/View.php`. A run nobody can answer (`--no-interaction`, or no terminal on stdin, as with CI and AI agents) skips the question, takes the default, and prints a note explaining the browser tab. `isInteractive()` treats unit tests as interactive, like Laravel's own prompt rule, so the tests are deterministic. The question uses `$this->confirm()`, not Laravel Prompts: the earlier `callSilent('vendor:publish')` reconfigures Prompts' global output to a `NullOutput`, which would make a Prompts question invisible. The browser opens through the `Process` facade (backgrounded on Linux, where `xdg-open` without a detected desktop runs the browser in the foreground), and any failure prints the URL instead.
- **`Support/View.php`** — Termwind console view renderer using PHP templates from `resources/views/components/`.

**Config** (`config/anthropic.php`): `anthropic.api_key` (env `ANTHROPIC_API_KEY`), `anthropic.request_timeout` (env `ANTHROPIC_REQUEST_TIMEOUT`, default `30`), `anthropic.beta` (env `ANTHROPIC_BETA`, a comma-separated list the provider joins into a global `anthropic-beta` header), `anthropic.api_version` (env `ANTHROPIC_API_VERSION`) and `anthropic.base_url` (env `ANTHROPIC_BASE_URL`), plus `anthropic.auth_token` (`ANTHROPIC_AUTH_TOKEN`, sent as Bearer through `withAuthToken()` only when no key is set: one credential, as in the official SDKs; `ApiKeyIsMissing` only when neither is set), `anthropic.workspace_id` (`ANTHROPIC_WORKSPACE_ID`), `anthropic.connect_timeout` (`ANTHROPIC_CONNECT_TIMEOUT`, Guzzle's `connect_timeout` when a positive number), `anthropic.max_retries` (`ANTHROPIC_MAX_RETRIES`, `withMaxRetries()` when a positive number), `anthropic.http.middleware` (Guzzle middleware class names the container resolves, or callables, pushed onto a `HandlerStack` the provider builds; one class name alone counts as a list, and an entry that isn't a middleware throws `InvalidArgumentException`) and `anthropic.headers` (an array of string, int or float values, no env variable, applied last). `auth_token`, `workspace_id`, `api_version` and `base_url` default to null and apply only as non-blank strings, so a config published before they existed (the provider merges no defaults) keeps the client's own defaults. `base_url` has the official SDKs' meaning, the URL without the API's `/v1`: the provider passes it to the client's `withBaseUrl()`, which trims it and adds `/v1` unless it already ends in `/v1` (one rule for both packages).

The install command adds each `.env` key a file lacks (an anchored match, commented lines count), so a re-run brings an older `.env` up to date. The optional keys go in commented out: other packages read the same names (Prism reads `ANTHROPIC_API_VERSION`), and Laravel's `env()` returns `''` for an empty line, which drops their default. When `config/anthropic.php` already exists, it names the keys the package's config has that the published copy lacks, since their `.env` variables do nothing until they're added.

**Underlying client** (`mozex/anthropic-php`) exposes `->messages()`, `->completions()`, `->models()`, `->batches()`, and `->files()`.

## Code Conventions

- All files use `declare(strict_types=1)`
- No class is `final`: users extend package classes
- Non-public-API classes use `@internal` docblock
- No debugging statements: `dd`, `ddd`, `dump`, `ray`, `die`, `var_dump`, `print_r`
- PHPStan at level `max` on `src/` and `config/`, with Larastan — no baseline. The one ignore is Larastan's `noEnvCallsOutsideOfConfig` for `config/anthropic.php`, where `env()` belongs
- Pint with default Laravel ruleset (no `pint.json`)

## Testing

Tests use **Pest** syntax with `expect()` assertions. Every test file ends in `Test.php`: `phpunit.xml.dist` lists `tests` without a suffix, so PHPUnit's default `Test.php` applies and any other file there is silently skipped (a test in `tests/ArchTest.php` fails on one). `tests/Pest.php` binds nothing: the facade and provider tests build their own container. Testbench, like an app's own test case, bootstraps the console kernel, which loads every deferred provider before a test runs, so a test about deferred loading (the fake test after `loadDeferredProviders()`) builds a fresh `Illuminate\Foundation\Application` with `addDeferredServices()` and restores the global container and the facade application afterwards.

Architecture tests in `tests/ArchTest.php` enforce namespace dependency boundaries — each namespace declares which imports are allowed.

`tests/InstallCommandTest.php` runs the install command, and `vendor:publish` by tag and by provider, through Orchestra Testbench (`tests/TestCase.php` registers the provider); it's the only file bound to that TestCase. It fakes `Process`, so no test opens a real browser, and restores the testbench skeleton's `.env.example` afterwards.

Facade fake pattern:
```php
Anthropic::fake([CreateResponse::fake(['id' => 'msg_test'])]);
$result = Anthropic::messages()->create([...]);
Anthropic::assertSent(Messages::class, fn (string $method, array $parameters) => $method === 'create');
```

## CI

Tests run across: PHP 8.2-8.5, Laravel 12/13 (with their testbench and carbon versions; Laravel 13 skips PHP 8.2), prefer-lowest/prefer-stable. There's no Pest dimension: prefer-stable cells get the newest Pest major each PHP version allows, prefer-lowest cells the oldest one that resolves (Pest 3 with Laravel 12).
