# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`mozex/anthropic-laravel` — A Laravel integration package wrapping `mozex/anthropic-php` (framework-agnostic Anthropic API client). Exposes the client via a service provider, facade, and Artisan install command. Namespace: `Anthropic\Laravel\`. Requires PHP 8.2+, Laravel 11 or 12.

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
- **`Commands/InstallCommand.php`** — `php artisan anthropic:install`. Copies config, appends env vars to `.env`/`.env.example`. Uses Termwind for console output via `Support/View.php`.
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

Facade fake pattern:
```php
Anthropic::fake([CreateResponse::fake(['completion' => 'awesome!'])]);
$result = Anthropic::completions()->create([...]);
Anthropic::assertSent(Completions::class, fn (string $method, array $parameters) => $method === 'create');
```

## CI

Tests run across: PHP 8.2/8.3/8.4, Laravel 11/12, Pest 3/4, prefer-lowest/prefer-stable.
