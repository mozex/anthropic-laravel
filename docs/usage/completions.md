---
title: Completions
weight: 10
---

> **Legacy API.** The Text Completions API is Anthropic's older interface. For new projects, use the [Messages API](./messages.md) instead.

The Completions resource is included for backward compatibility with projects that still use it.

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

---

For the full request and response specification, see the [Completions page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/completions) or the [Text Completions API reference](https://platform.claude.com/docs/en/api/completions/create) on the Anthropic docs.
