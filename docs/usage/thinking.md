---
title: Thinking
weight: 4
---

Extended thinking lets Claude reason through complex problems before answering. Useful for math, logic, analysis, and code generation.

## Adaptive thinking

The recommended approach for Claude Opus 4.6 and Sonnet 4.6. Claude decides how much to think based on the request:

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 16000,
    'thinking' => [
        'type' => 'adaptive',
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Explain why the sum of two even numbers is always even.'],
    ],
]);
```

The response content may include `thinking` blocks before the final `text` block:

```php
foreach ($response->content as $block) {
    if ($block->type === 'thinking') {
        $block->thinking;  // 'Let me analyze this step by step...'
        $block->signature; // 'WaUjzkypQ2mUEVM36O2TxuC06KN8xyfbJwyem...'
    }

    if ($block->type === 'text') {
        $block->text; // 'Based on my analysis...'
    }
}
```

## Effort levels

Control how much Claude thinks with `output_config.effort`:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 16000,
    'thinking' => ['type' => 'adaptive'],
    'output_config' => [
        'effort' => 'medium',
    ],
    'messages' => [...],
]);
```

| Effort | Behavior |
|--------|----------|
| `max` | No constraints on thinking depth. Opus 4.6 and Sonnet 4.6. |
| `high` | Default. Thorough thinking. |
| `medium` | Moderate thinking. May skip for simple queries. |
| `low` | Minimal thinking. Skips for trivial queries. |

## Budget-based thinking

For older models (Sonnet 3.7, Opus 4.5, Sonnet 4.5), use a fixed token budget. This is deprecated on Opus 4.6 and Sonnet 4.6:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-5',
    'max_tokens' => 16000,
    'thinking' => [
        'type' => 'enabled',
        'budget_tokens' => 10000,
    ],
    'messages' => [...],
]);
```

## Thinking in queued jobs

Thinking requests often take 30+ seconds. Dispatch them to a queue, and bump your timeout:

```env
ANTHROPIC_REQUEST_TIMEOUT=120
```

```php
class AnalyzeJob implements ShouldQueue
{
    public $timeout = 150;

    public function handle(): void
    {
        $response = Anthropic::messages()->create([
            'model' => 'claude-opus-4-6',
            'max_tokens' => 16000,
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => 'max'],
            'messages' => $this->messages,
        ]);

        // Save the final answer (skip thinking blocks for display)
        $text = collect($response->content)
            ->firstWhere('type', 'text')
            ->text;

        $this->result->update(['content' => $text]);
    }
}
```

## Multi-turn with thinking

Pass the full content array (thinking blocks included) back in follow-up requests so Claude can verify its previous reasoning:

```php
$followUp = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 16000,
    'thinking' => ['type' => 'adaptive'],
    'messages' => [
        ['role' => 'user', 'content' => 'Solve x^2 + 5x + 6 = 0'],
        ['role' => 'assistant', 'content' => $response->toArray()['content']],
        ['role' => 'user', 'content' => 'Now verify by substitution.'],
    ],
]);
```

The `signature` field on each thinking block is used by the server to verify integrity, so don't modify it.

---

For display options (`summarized` vs `omitted`), `redacted_thinking` blocks, interleaved thinking with tools, and streaming behavior, see the [Thinking page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/thinking) or the [Extended thinking guide](https://platform.claude.com/docs/en/build-with-claude/extended-thinking) and [Adaptive thinking guide](https://platform.claude.com/docs/en/build-with-claude/adaptive-thinking) on the Anthropic docs.
