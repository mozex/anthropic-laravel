---
title: Completions
weight: 10
---

> **Deprecated.** The API now rejects Text Completions requests on current models with a 400 saying the endpoint has been deprecated. Use the [Messages API](./messages.md) instead.

`Anthropic::completions()` stays for code that still references it. The resource behind it is marked `@deprecated`, so PHPStan's deprecation rules flag the `create()` and `createStreamed()` calls chained on it, through the facade too. The facade's own `completions()` can't carry the deprecation in its `@method` docblock, so an IDE may not strike it through; search your code for `Anthropic::completions(` when you move off it.

The examples below show the request and response shapes. They use `claude-2.1`, a retired model, and don't run against today's API.

## Creating a completion

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::completions()->create([
    'model' => 'claude-2.1',
    'prompt' => "\n\nHuman: Hello, Claude\n\nAssistant:",
    'max_tokens_to_sample' => 100,
    'temperature' => 0,
]);

$response->completion;  // ' Hello! Nice to meet you.'
$response->stop_reason; // 'stop_sequence'
$response->model;       // 'claude-2.1'
```

The prompt format uses specific `\n\nHuman:` / `\n\nAssistant:` turn markers rather than a messages array.

## Streamed completions

```php
$stream = Anthropic::completions()->createStreamed([
    'model' => 'claude-2.1',
    'prompt' => 'Hi',
    'max_tokens_to_sample' => 70,
]);

foreach ($stream as $response) {
    echo $response->completion;
}
```

## Moving to Messages

The prompt becomes a user message, and `max_tokens_to_sample` becomes `max_tokens`. Leave `temperature` out: current models return a 400 for it.

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 100,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, Claude'],
    ],
]);

collect($response->content)->firstWhere('type', 'text')?->text;
```

---

For the full request and response specification, see the [Completions page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/completions) or the [Text Completions API reference](https://platform.claude.com/docs/en/api/completions/create) on the Anthropic docs.
