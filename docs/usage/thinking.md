---
title: Thinking
weight: 4
---

Extended thinking lets Claude reason through complex problems before answering. Useful for math, logic, analysis, and code generation.

## Adaptive thinking

The default on every Claude 5 model, and opt-in on Opus 4.8, Opus 4.7, Opus 4.6 and Sonnet 4.6. Claude decides how much to think based on the request:

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-opus-5-5',
    'max_tokens' => 16000,
    'thinking' => [
        'type' => 'adaptive',
        'display' => 'summarized',
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

On Claude 5 models, Opus 4.8 and Opus 4.7, `display` defaults to `omitted`: the `thinking` blocks come back with an empty `thinking` and only their `signature`. Set `'display' => 'summarized'` when you want to show the reasoning.

## Effort levels

Control how much Claude thinks with `output_config.effort`:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-opus-5-5',
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
| `max` | No constraints on thinking depth. |
| `xhigh` | Between `high` and `max`. |
| `high` | Thorough thinking. |
| `medium` | Moderate thinking. May skip for simple queries. |
| `low` | Minimal thinking. Skips for trivial queries. |

The default level differs per model (`high` on Sonnet 5.5, `medium` on Opus 5.5), and not every model takes every level.

## Turning thinking off and budget-based thinking

Fable 5.1, Fable 5, Mythos and Opus 5.5 can't turn thinking off. Sonnet 5.5 takes `'thinking' => ['type' => 'between_tools']` in place of `disabled`, at effort `high` or below.

Opus 4.5, Sonnet 4.5 and Haiku 4.5 use a fixed token budget instead of adaptive thinking. The budget is deprecated on Opus 4.6 and Sonnet 4.6, and Claude 4.7 and later models return a 400 for it:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-haiku-4-5',
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
            'model' => 'claude-opus-5-5',
            'max_tokens' => 16000,
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => 'max'],
            'messages' => $this->messages,
        ]);

        // Save the final answer (skip thinking blocks for display)
        $text = collect($response->content)
            ->firstWhere('type', 'text')
            ?->text; // null if max_tokens ran out while thinking

        $this->result->update(['content' => $text]);
    }
}
```

The job's `$timeout` sits above the request timeout, and the queue connection's `retry_after` in `config/queue.php` has to sit above the job's `$timeout`. With a lower `retry_after`, the worker releases the job to another process while the first still waits on Claude, and the same request is paid for twice.

## Multi-turn with thinking

Pass the full content array (thinking blocks included) back in follow-up requests so Claude can verify its previous reasoning:

```php
$followUp = Anthropic::messages()->create([
    'model' => 'claude-opus-5-5',
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

For display options, the full table of which models accept `disabled` and `budget_tokens`, thinking blocks the API drops on replay (`input_transformations`), `redacted_thinking` blocks, interleaved thinking with tools, and streaming behavior, see the [Thinking page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/thinking) or the [Extended thinking guide](https://platform.claude.com/docs/en/build-with-claude/extended-thinking) and [Adaptive thinking guide](https://platform.claude.com/docs/en/build-with-claude/adaptive-thinking) on the Anthropic docs.
