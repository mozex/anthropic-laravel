# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`mozex/anthropic-laravel` — A Laravel integration package wrapping `mozex/anthropic-php` (framework-agnostic Anthropic API client). Exposes the client via a service provider, facade, and Artisan install command. Namespace: `Anthropic\Laravel\`. Requires PHP 8.2+, Laravel 12 or 13.

## Commands

```bash
composer lint             # Fix code style with Pint
composer test             # Run ALL checks: lint, types, unit tests
composer test:lint        # Check code style without fixing
composer test:types       # Run PHPStan (level max)
composer test:unit        # Run Pest unit tests
```

Run a single test file:
```bash
./vendor/bin/pest tests/Facades/Anthropic.php
```

Run tests by name:
```bash
./vendor/bin/pest --filter="test name substring"
```

## Architecture

Small package with 6 source files in `src/`:

- **`ServiceProvider.php`** — `DeferrableProvider` that registers `ClientContract` as a lazy singleton, aliased to `'anthropic'` and `Client::class`. Auto-discovered via `extra.laravel.providers` in `composer.json`. Publishes `config/anthropic.php`.
- **`Facades/Anthropic.php`** — Facade resolving `'anthropic'`. Has `fake(array $responses)` for testing (swaps root with `AnthropicFake`).
- **`Testing/AnthropicFake.php`** — Thin subclass of `Anthropic\Testing\ClientFake`. Provides `assertSent()`, `assertNotSent()`, `assertNothingSent()`.
- **`Exceptions/ApiKeyIsMissing.php`** — Thrown when `anthropic.api_key` config is missing or not a string.
- **`Commands/InstallCommand.php`** — `php artisan anthropic:install`. Copies config, appends env vars to `.env`/`.env.example`, then asks for a GitHub star (default yes). Uses Termwind for console output via `Support/View.php`. A run nobody can answer (`--no-interaction`, or no terminal on stdin, as with CI and AI agents) skips the question, takes the default, and prints a note explaining the browser tab. `isInteractive()` treats unit tests as interactive, like Laravel's own prompt rule, so the tests are deterministic. The question uses `$this->confirm()`, not Laravel Prompts: the earlier `callSilent('vendor:publish')` reconfigures Prompts' global output to a `NullOutput`, which would make a Prompts question invisible. The browser opens through the `Process` facade (backgrounded on Linux, where `xdg-open` without a detected desktop runs the browser in the foreground), and any failure prints the URL instead.
- **`Support/View.php`** — Termwind console view renderer using PHP templates from `resources/views/components/`.

**Config** (`config/anthropic.php`): `anthropic.api_key` (env `ANTHROPIC_API_KEY`), `anthropic.request_timeout` (env `ANTHROPIC_REQUEST_TIMEOUT`, default `30`).

**Underlying client** (`mozex/anthropic-php`) exposes `->messages()`, `->completions()`, `->models()`, and `->batches()`.

## Code Conventions

- All files use `declare(strict_types=1)`
- All classes are `final`
- Non-public-API classes use `@internal` docblock
- No debugging statements: `dd`, `ddd`, `dump`, `ray`, `die`, `var_dump`, `print_r`
- PHPStan at level `max` on `src/` — no baseline
- Pint with default Laravel ruleset (no `pint.json`)

## Testing

Tests use **Pest** syntax with `expect()` assertions.

Architecture tests in `tests/Arch.php` enforce namespace dependency boundaries — each namespace declares which imports are allowed.

`tests/Commands/InstallCommand.php` runs the install command through Orchestra Testbench (`tests/TestCase.php` registers the provider); it's the only file bound to that TestCase. It fakes `Process`, so no test opens a real browser, and restores the testbench skeleton's `.env.example` afterwards.

Facade fake pattern:
```php
Anthropic::fake([CreateResponse::fake(['id' => 'msg_test'])]);
$result = Anthropic::messages()->create([...]);
Anthropic::assertSent(Messages::class, fn (string $method, array $parameters) => $method === 'create');
```

## CI

Tests run across: PHP 8.2/8.3/8.4, Laravel 12/13, Pest 3/4, prefer-lowest/prefer-stable.
