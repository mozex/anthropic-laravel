# Anthropic Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)
[![GitHub Tests Workflow Status](https://img.shields.io/github/actions/workflow/status/mozex/anthropic-laravel/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/anthropic-laravel/actions/workflows/tests.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/anthropic-laravel/v1)
[![License](https://img.shields.io/github/license/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)

Laravel wrapper for [Anthropic PHP](https://github.com/mozex/anthropic-php), the community-maintained PHP SDK for the [Anthropic API](https://platform.claude.com/docs/en/about-claude/models/overview). Adds a Facade, a publishable config, an install command, and a testing integration that plugs into the service container.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/anthropic-laravel/v1)**: searchable docs, version requirements, detailed changelog, and more.

> **Not using Laravel?** Use the framework-agnostic [Anthropic PHP](https://github.com/mozex/anthropic-php) package directly.

## Table of Contents

- [Introduction](https://mozex.dev/docs/anthropic-laravel/v1)
- Usage
  - [Messages](https://mozex.dev/docs/anthropic-laravel/v1/usage/messages)
  - [Streaming](https://mozex.dev/docs/anthropic-laravel/v1/usage/streaming)
  - [Tool Use](https://mozex.dev/docs/anthropic-laravel/v1/usage/tool-use)
  - [Thinking](https://mozex.dev/docs/anthropic-laravel/v1/usage/thinking)
  - [Server Tools](https://mozex.dev/docs/anthropic-laravel/v1/usage/server-tools)
  - [Citations](https://mozex.dev/docs/anthropic-laravel/v1/usage/citations)
  - [Token Counting](https://mozex.dev/docs/anthropic-laravel/v1/usage/token-counting)
  - [Models](https://mozex.dev/docs/anthropic-laravel/v1/usage/models)
  - [Batches](https://mozex.dev/docs/anthropic-laravel/v1/usage/batches)
  - [Files](https://mozex.dev/docs/anthropic-laravel/v1/usage/files)
  - [Completions](https://mozex.dev/docs/anthropic-laravel/v1/usage/completions)
- Reference
  - [Configuration](https://mozex.dev/docs/anthropic-laravel/v1/reference/configuration)
  - [Error Handling](https://mozex.dev/docs/anthropic-laravel/v1/reference/error-handling)
  - [Meta Information](https://mozex.dev/docs/anthropic-laravel/v1/reference/meta-information)
  - [Testing](https://mozex.dev/docs/anthropic-laravel/v1/reference/testing)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://mozex.dev/docs) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## Why This Package

**`Anthropic::` Facade for everything.** `Anthropic::messages()`, `Anthropic::models()`, `Anthropic::batches()`, `Anthropic::files()`, `Anthropic::completions()`. No client instantiation, no factory setup. The service provider handles it.

**`Anthropic::fake()` in your tests.** Swap the real client with a fake, queue responses, and assert exactly which requests were sent. Pairs with Laravel's existing testing idioms like `Event::fake()` and `Queue::fake()`. [See the testing docs →](https://mozex.dev/docs/anthropic-laravel/v1/reference/testing)

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Resources\Messages;
use Anthropic\Responses\Messages\CreateResponse;

Anthropic::fake([
    CreateResponse::fake([
        'content' => [['type' => 'text', 'text' => 'Paris is the capital of France.']],
    ]),
]);

// Run your code...

Anthropic::assertSent(Messages::class, function (string $method, array $parameters) {
    return $parameters['model'] === 'claude-sonnet-4-6';
});
```

**One artisan command to set up.** `php artisan anthropic:install` publishes the config file and appends `ANTHROPIC_API_KEY=` to your `.env`. You're ready to go.

**Forward-compatible.** Parameters pass through to the API as-is. When Anthropic ships a new feature, it works in your code the same day. No waiting for a package update.

**Full Anthropic API coverage.** Messages, streaming, tool use, extended thinking, web search, code execution, citations, token counting, and batch processing. Every feature the API supports is available through the Facade.

## Installation

> **Requires [PHP 8.2+](https://www.php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/anthropic-laravel/v1/requirements)

```bash
composer require mozex/anthropic-laravel
```

Run the install command:

```bash
php artisan anthropic:install
```

This publishes `config/anthropic.php` and appends `ANTHROPIC_API_KEY=` to your `.env`. Set your key from the [Anthropic Console](https://platform.claude.com/settings/keys):

```env
ANTHROPIC_API_KEY=sk-ant-...
```

## Quick Start

Use the `Anthropic` Facade anywhere in your app:

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

echo $response->content[0]->text; // Hello! How can I assist you today?
```

### Streaming

Print text as it arrives:

```php
$stream = Anthropic::messages()->createStreamed([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Tell me a short story.'],
    ],
]);

foreach ($stream as $response) {
    if ($response->type === 'content_block_delta'
        && $response->delta->type === 'text_delta') {
        echo $response->delta->text;
    }
}
```

### Tool Use

Give Claude tools to call, execute them in your code, send results back:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'tools' => [
        [
            'name' => 'get_weather',
            'description' => 'Get the current weather in a given location',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
                'required' => ['location'],
            ],
        ],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'What is the weather in San Francisco?'],
    ],
]);

$response->content[1]->name;              // 'get_weather'
$response->content[1]->input['location']; // 'San Francisco'
```

### Extended Thinking

Let Claude reason through complex problems before answering:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 16000,
    'thinking' => ['type' => 'adaptive'],
    'messages' => [
        ['role' => 'user', 'content' => 'What is the GCD of 1071 and 462?'],
    ],
]);

$response->content[0]->thinking; // 'Using the Euclidean algorithm...'
$response->content[1]->text;     // 'The GCD of 1071 and 462 is 21.'
```

The [full documentation](https://mozex.dev/docs/anthropic-laravel/v1) covers every feature: [vision and images](https://mozex.dev/docs/anthropic-laravel/v1/usage/messages), [web search and code execution](https://mozex.dev/docs/anthropic-laravel/v1/usage/server-tools), [document citations](https://mozex.dev/docs/anthropic-laravel/v1/usage/citations), [batch processing](https://mozex.dev/docs/anthropic-laravel/v1/usage/batches), [error handling](https://mozex.dev/docs/anthropic-laravel/v1/reference/error-handling), [testing](https://mozex.dev/docs/anthropic-laravel/v1/reference/testing), and [more](https://mozex.dev/docs/anthropic-laravel/v1).

## Resources

Visit the [documentation site](https://mozex.dev/docs/anthropic-laravel/v1) for searchable docs auto-updated from this repository.

- **[AI Integration](https://mozex.dev/docs/anthropic-laravel/v1/ai-integration)**: Use this package with AI coding assistants via Context7 and Laravel Boost
- **[Requirements](https://mozex.dev/docs/anthropic-laravel/v1/requirements)**: PHP, Laravel, and dependency versions
- **[Changelog](https://mozex.dev/docs/anthropic-laravel/v1/changelog)**: Release history with linked pull requests and diffs
- **[Contributing](https://mozex.dev/docs/anthropic-laravel/v1/contributing)**: Development setup, code quality, and PR guidelines
- **[Questions & Issues](https://mozex.dev/docs/anthropic-laravel/v1/questions-and-issues)**: Bug reports, feature requests, and help
- **[Security](mailto:hello@mozex.dev)**: Report vulnerabilities directly via email

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
