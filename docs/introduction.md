---
title: Introduction
weight: 0
---

Anthropic Laravel is the Laravel wrapper for [Anthropic PHP](https://mozex.dev/docs/anthropic-php/v1), the community-maintained PHP SDK for the [Anthropic API](https://platform.claude.com/docs/en/about-claude/models/overview). It gives you a Laravel-native way to talk to Claude: a Facade, a publishable config, an install command, and a testing integration that plugs into the service container.

Under the hood, every request is handled by the underlying PHP package, so you get full access to every Anthropic API feature (messages, streaming, tool use, extended thinking, web search, code execution, citations, token counting, and batches) without the framework-agnostic boilerplate.

> **Not using Laravel?** Use the framework-agnostic [Anthropic PHP](https://github.com/mozex/anthropic-php) package directly.

## Why this package

**One Facade for everything.** `Anthropic::messages()`, `Anthropic::models()`, `Anthropic::batches()`, `Anthropic::completions()`. No factory setup, no client instantiation. The service provider handles it.

**Publishable config with env support.** Run `php artisan anthropic:install` once and you've got a `config/anthropic.php` file, an `ANTHROPIC_API_KEY` entry in `.env`, and you're ready to go.

**`Anthropic::fake()` in your tests.** Swap the real client with a fake one, queue responses, and assert exactly which requests were sent. This pairs with Laravel's existing testing idioms (`Event::fake()`, `Queue::fake()`) so your test code stays consistent.

**Same-day support for new API features.** Parameters pass through to the API as-is. New content block types, new thinking modes, new tool versions all work the day Anthropic ships them. You don't wait for a package update.

## Installation

> **Requires [PHP 8.2+](https://www.php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/anthropic-laravel/v1/requirements)

Install via Composer:

```bash
composer require mozex/anthropic-laravel
```

Run the install command:

```bash
php artisan anthropic:install
```

This publishes `config/anthropic.php` to your project and appends `ANTHROPIC_API_KEY=` to your `.env` file. Fill it in with your key from the [Anthropic Console](https://platform.claude.com/settings/keys):

```env
ANTHROPIC_API_KEY=sk-ant-...
```

That's the whole setup. The service provider registers the client as a singleton in the container, so you can start using the Facade right away.

## Quick start

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

The response is a typed, readonly object with properties for the message ID, model, content blocks, token usage, and stop reason:

```php
$response->id;                  // 'msg_01BSy0WCV7QR2adFBauynAX7'
$response->model;               // 'claude-sonnet-4-6'
$response->stop_reason;         // 'end_turn'
$response->usage->inputTokens;  // 10
$response->usage->outputTokens; // 19
```

## Configuration

For most apps, the two env vars are enough:

```env
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_REQUEST_TIMEOUT=30
```

The request timeout defaults to 30 seconds. For long-running requests (large context windows, extended thinking, code execution), bump it up. See [Configuration](./reference/configuration.md) for details.

## Testing

In your tests, swap the real client with a fake using `Anthropic::fake()`:

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
$this->post('/ask', ['question' => 'What is the capital of France?']);

// Then assert what was sent
Anthropic::assertSent(Messages::class, function (string $method, array $parameters): bool {
    return $method === 'create' && $parameters['model'] === 'claude-sonnet-4-6';
});
```

See [Testing](./reference/testing.md) for the full set of fake patterns and assertions.

## What's covered

**Usage** covers all API operations:

- [Messages](./usage/messages.md): Send messages and read responses
- [Streaming](./usage/streaming.md): Stream responses token by token
- [Tool Use](./usage/tool-use.md): Give Claude custom functions to call
- [Thinking](./usage/thinking.md): Adaptive and extended thinking for complex reasoning
- [Server Tools](./usage/server-tools.md): Web search and sandboxed code execution
- [Citations](./usage/citations.md): Source citations from documents and web search
- [Token Counting](./usage/token-counting.md): Count tokens before sending
- [Models](./usage/models.md): List and inspect available models
- [Batches](./usage/batches.md): Process large volumes of requests asynchronously
- [Completions](./usage/completions.md): Legacy Text Completions API

**Reference** covers cross-cutting concerns:

- [Configuration](./reference/configuration.md): Config file, env vars, timeouts, container binding
- [Error Handling](./reference/error-handling.md): Exception types and catching them in Laravel
- [Meta Information](./reference/meta-information.md): Rate limits, request IDs, token limits
- [Testing](./reference/testing.md): `Anthropic::fake()`, mock responses, and assertions
