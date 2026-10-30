---
title: Token Counting
weight: 7
---

The token counting endpoint tells you how many input tokens a message would use without actually creating it. Useful for cost estimation, staying within context limits, or trimming conversation history before sending.

## Counting tokens

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->countTokens([
    'model' => 'claude-sonnet-5-5',
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, world'],
    ],
]);

$response->inputTokens; // 2095
```

Same parameters as `create()`, but it doesn't generate a response. You can include `tools`, `system`, and anything else that contributes to the token count.

## Fitting a conversation in the limit

`fits()` tells you whether a request is within a number of input tokens, and `trimToFit()` drops the oldest turns until it is. Pass them the parameters you'll give `create()`; they count only what `count_tokens` takes:

```php
$parameters = [
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'system' => 'You are a support assistant.',
    'messages' => $conversation->messages->map->toClaude()->all(),
];

$response = Anthropic::messages()->create(
    Anthropic::messages()->trimToFit($parameters, 150000),
);
```

`trimToFit()` only cuts before a user turn that answers no tool call, so tool calls keep their results, and it keeps the system prompt and the tools (a `role: system` message among the dropped turns goes with them). When even the last turn is over the limit, it throws `Anthropic\Exceptions\InvalidArgumentException`. The limit counts input only; a model's own is `maxInputTokens` on `Anthropic::models()->retrieve($model)`.

A conversation with thinking blocks also needs `'thinking' => [..., 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']]` and the `thinking-binding-controls-2026-08-01` beta, or Claude 5.1 models reject the kept turns' thinking with a 400. Server tools, the MCP connector and `url` or `file` sources can't be counted, so the count throws for those. The [PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/token-counting#trimming-a-conversation-to-fit) have the details.

## Validating a prompt's length

`FitsInTokens` fails a text that would count more than the limit as one user message:

```php
use Anthropic\Laravel\Rules\FitsInTokens;

$request->validate([
    'prompt' => ['bail', 'required', 'string', 'max:20000', new FitsInTokens(4000, 'claude-sonnet-5-5')],
]);
```

`->withSystem($prompt)` counts it with your system prompt, and `->onConnection('support')` counts through another connection. Each check is a `count_tokens` call, free but rate limited, so put the rule last, behind `bail`, which skips it once an earlier rule failed. A text too large for the API to count fails the rule; any other failed count throws the API's exception, since it isn't the input's fault.

From the terminal, `php artisan anthropic:count-tokens "Hello" --model=claude-sonnet-5-5` counts a text, `--file=prompt.md` a file, and `--system=` adds a system prompt.

## Cost estimation before sending

If you bill users for Claude usage, count tokens first and show them an estimate:

```php
$count = Anthropic::messages()->countTokens([
    'model' => $request->input('model'),
    'system' => $request->input('system'),
    'messages' => $request->input('messages'),
]);

$estimatedCost = $count->inputTokens * $inputRatePerToken;

return response()->json([
    'estimated_input_tokens' => $count->inputTokens,
    'estimated_cost' => $estimatedCost,
]);
```

---

For the full request specification, see the [Token Counting page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/token-counting) or the [Count Tokens API reference](https://platform.claude.com/docs/en/api/messages/count_tokens) on the Anthropic docs.
