---
title: Using alongside laravel/ai
weight: 5
---

[laravel/ai](https://github.com/laravel/ai) gives an app one API for many providers. This package is the Claude companion beside it: it covers what only Claude has, so the two sit in the same app without stepping on each other.

Reach for this package when the work is Claude's own: Message Batches, token counting, the Files and Skills APIs, the rate-limit headers on every response, refusal fallback with fallback credit, Managed Agents, and the Admin and usage reports. Keep laravel/ai for the provider-neutral parts of the app.

## One key for both

An app set up for laravel/ai already has its Anthropic key in `config/ai.php`. When this package's config has no key or token of its own, because you haven't published `config/anthropic.php` or because `config/ai.php` reads its key from another variable, this package uses laravel/ai's key with its URL, as a pair:

```php
// config/ai.php, as laravel/ai publishes it
'anthropic' => [
    'driver' => 'anthropic',
    'key' => env('ANTHROPIC_API_KEY'),
    'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
],
```

So `Anthropic::messages()` works with nothing more to set, and `withApiKey()` and `organization()` follow the same URL. Named connections never take laravel/ai's key. A key of this package's own always wins, and then laravel/ai's URL isn't used either: set `ANTHROPIC_BASE_URL` for a gateway.

```php
use Anthropic\Laravel\Facades\Anthropic;

// counted with the same key laravel/ai sends prompts with
$tokens = Anthropic::messages()->countTokens([
    'model' => 'claude-sonnet-5-5',
    'messages' => [['role' => 'user', 'content' => $prompt]],
])->inputTokens;
```

---

For everything the client covers, see the [PHP docs](https://mozex.dev/docs/anthropic-php/v1) or the [Anthropic API reference](https://platform.claude.com/docs/en/api/overview).
