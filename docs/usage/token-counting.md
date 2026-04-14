---
title: Token Counting
weight: 7
---

The token counting endpoint tells you how many input tokens a message would use without actually creating it. Useful for cost estimation, staying within context limits, or trimming conversation history before sending.

## Counting tokens

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->countTokens([
    'model' => 'claude-sonnet-4-6',
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, world'],
    ],
]);

$response->inputTokens; // 2095
```

Same parameters as `create()`, but it doesn't generate a response. You can include `tools`, `system`, and anything else that contributes to the token count.

## Trimming conversation history

A common Laravel pattern is to count tokens before sending and trim older messages if you're close to the context limit:

```php
$messages = $conversation->messages()
    ->latest('id')
    ->take(50)
    ->get()
    ->reverse()
    ->map(fn ($message) => [
        'role' => $message->is_assistant ? 'assistant' : 'user',
        'content' => $message->content,
    ])
    ->toArray();

$count = Anthropic::messages()->countTokens([
    'model' => 'claude-sonnet-4-6',
    'messages' => $messages,
]);

if ($count->inputTokens > 180000) {
    // Drop the oldest pairs until we're under the limit
    $messages = array_slice($messages, 4);
}

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => $messages,
]);
```

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
