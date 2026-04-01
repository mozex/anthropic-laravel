[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)
[![GitHub Tests Workflow Status](https://img.shields.io/github/actions/workflow/status/mozex/anthropic-laravel/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/anthropic-laravel/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)

------
**Anthropic Laravel** is a community-maintained PHP API client that allows you to interact with the [Anthropic API](https://docs.anthropic.com/claude/docs/intro-to-claude).

> **Note:** This repository contains the integration code of the **Anthropic PHP** for Laravel. If you want to use the **Anthropic PHP** client in a framework-agnostic way, take a look at the [mozex/anthropic-php](https://github.com/mozex/anthropic-php) repository.

## Why Anthropic Laravel?

With the official Anthropic SDK and Laravel's own AI SDK available, you might wonder which to use. Here's how they compare:

| | **Anthropic Laravel** | **Laravel AI SDK** | **Official Anthropic SDK** |
|---|---|---|---|
| **Anthropic API coverage** | Full — messages, streaming, tool use, vision, batches, models, adaptive thinking, web search, code execution, citations, token counting | Unified API across providers — covers core features | Full |
| **Multi-provider support** | Anthropic only | OpenAI, Anthropic, Gemini, Groq, xAI | Anthropic only |
| **Laravel integration** | Facade, config publishing, service provider | Native — agents, queuing, conversation memory | None — framework-agnostic |
| **Testing** | `Anthropic::fake()` with per-resource assertions, full parameter inspection, and response mocking at the API level | Higher-level fakes per capability — no direct API parameter assertions | None built-in |
| **Laravel version support** | 11+ | 12+ | Any (no Laravel dependency) |
| **PHP version** | 8.2+ | 8.3+ | 8.1+ |
| **New Anthropic features** | Same-day support | Follows unified release cycle | Same-day support |

### Choose Anthropic Laravel when you:

- Need **full access to every Anthropic API feature** — including batches, adaptive thinking, web search, code execution, citations, token counting, and model management
- Want **granular test control** — `Anthropic::fake()` lets you mock exact API responses and assert on specific resource methods and parameters, something no other Laravel integration offers at this level
- Want a **Laravel-native experience** (Facades, config, testing) without sacrificing API depth
- Are on **Laravel 11** (Laravel AI SDK requires 12+)
- Want **same-day support** when Anthropic ships new features
- Prefer a **thin, focused wrapper** over a multi-provider abstraction

### Choose Laravel AI SDK when you:

- Need to **switch between AI providers** (OpenAI, Gemini, etc.) with one codebase
- Want built-in **agent architecture** with conversation memory and provider failover
- Are building a new **Laravel 12+** project and don't need Anthropic-specific features

Both packages can coexist — use Laravel AI SDK for multi-provider features and Anthropic Laravel for deep Anthropic integration.

## Table of Contents

- [Why Anthropic Laravel?](#why-anthropic-laravel)
- [Support This Project](#support-this-project)
- [Get Started](#get-started)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Adaptive Thinking](#adaptive-thinking)
  - [Web Search](#web-search)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [License](#license)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://github.com/mozex?tab=repositories&q=&type=source) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## Get Started

> **Requires [PHP 8.2+](https://www.php.net/releases/)**

First, install Anthropic via the [Composer](https://getcomposer.org/) package manager:

```bash
composer require mozex/anthropic-laravel
```

Next, execute the install command:

```bash
php artisan anthropic:install
```

This will create a `config/anthropic.php` configuration file in your project, which you can modify to your needs
using environment variables.
Blank environment variable for the Anthropic API key is already appended to your `.env` file.

```env
ANTHROPIC_API_KEY=sk-...
```

Finally, you may use the `Anthropic` facade to access the Anthropic API:

```php
use Anthropic\Laravel\Facades\Anthropic;

$result = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

echo $result->content[0]->text; // Hello! How can I assist you today?
```

## Configuration

Configuration is done via environment variables or directly in the configuration file (`config/anthropic.php`).

### Anthropic API Key

Specify your Anthropic API Key. This will be used to authenticate with the Anthropic API - you can find your API key on your Anthropic dashboard, at https://console.anthropic.com/settings/keys.

```env
ANTHROPIC_API_KEY=
```

### Request Timeout

The timeout may be used to specify the maximum number of seconds to wait
for a response. By default, the client will time out after 30 seconds.

```env
ANTHROPIC_REQUEST_TIMEOUT=
```

## Usage

For detailed usage examples, take a look at the [mozex/anthropic-php](https://github.com/mozex/anthropic-php) repository.

The following resources are available through the `Anthropic` facade:

```php
use Anthropic\Laravel\Facades\Anthropic;

// Messages (primary API)
Anthropic::messages()->create([...]);
Anthropic::messages()->createStreamed([...]);
Anthropic::messages()->countTokens([...]);

// Models
Anthropic::models()->list();
Anthropic::models()->retrieve('claude-sonnet-4-6');

// Message Batches
Anthropic::batches()->create([...]);
Anthropic::batches()->retrieve('msgbatch_...');
Anthropic::batches()->list();
Anthropic::batches()->cancel('msgbatch_...');
Anthropic::batches()->delete('msgbatch_...');
Anthropic::batches()->results('msgbatch_...');

// Legacy Completions
Anthropic::completions()->create([...]);
```

### Adaptive Thinking

Use adaptive thinking to let Claude adjust its reasoning depth dynamically:

```php
$result = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 16000,
    'temperature' => 1, // required for thinking
    'thinking' => [
        'type' => 'enabled',
        'budget_tokens' => 10000,
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Explain quantum entanglement.'],
    ],
]);
```

### Web Search

Enable Claude to search the web and cite sources in its responses:

```php
$result = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'tools' => [
        [
            'type' => 'web_search_20250305',
            'name' => 'web_search',
        ],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'What are the latest developments in fusion energy?'],
    ],
]);
```

## Testing

The `Anthropic` facade comes with a `fake()` method that allows you to fake the API responses.

The fake responses are returned in the order they are provided to the `fake()` method.

All responses have a `fake()` method that allows you to easily create a response object by only providing the parameters relevant for your test case.

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Resources\Messages;
use Anthropic\Responses\Messages\CreateResponse;

Anthropic::fake([
    CreateResponse::fake([
        'id' => 'msg_test',
    ]),
]);

$result = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

expect($result)->id->toBe('msg_test');
```

After the requests have been sent there are various methods to ensure that the expected requests were sent:

```php
// assert a messages create request was sent
Anthropic::assertSent(Messages::class, function (string $method, array $parameters): bool {
    return $method === 'create' &&
        $parameters['model'] === 'claude-sonnet-4-6';
});
```

You can also assert on specific resources:

```php
Anthropic::messages()->assertSent(function (string $method, array $parameters): bool {
    return $method === 'create';
});
```

Other available assertion methods:

```php
// assert that nothing was sent
Anthropic::assertNothingSent();

// assert that a specific resource was not called
Anthropic::assertNotSent(Messages::class);
```

For more testing examples, take a look at the [mozex/anthropic-php](https://github.com/mozex/anthropic-php#testing) repository.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mozex](https://github.com/mozex)
- [Nuno Maduro](https://github.com/nunomaduro) and [Sandro Gehri](https://github.com/gehrisandro) for their work on [openai-php](https://github.com/openai-php/client), which inspired this package
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
