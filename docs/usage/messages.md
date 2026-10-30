---
title: Messages
weight: 1
---

The Messages API is the primary way to interact with Claude. Send messages using the `Anthropic` Facade and get back typed response objects.

## Creating a message

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, world'],
    ],
]);
```

The response is a `CreateResponse` object with typed properties:

```php
$response->id;            // 'msg_01BSy0WCV7QR2adFBauynAX7'
$response->model;         // 'claude-sonnet-5-5'
$response->stop_reason;   // 'end_turn'
$response->content[0]->text; // 'Hello! How can I assist you today?'

$response->usage->inputTokens;  // 10
$response->usage->outputTokens; // 19
```

## Multi-turn conversations

The Anthropic API is stateless, so you pass the full conversation history on every request:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'What is PHP?'],
        ['role' => 'assistant', 'content' => 'PHP is a server-side scripting language...'],
        ['role' => 'user', 'content' => 'What version should I use?'],
    ],
]);
```

A common Laravel pattern is to store the conversation in a model and build the array from Eloquent:

```php
$messages = $conversation->messages()
    ->latest('id')
    ->take(20)
    ->get()
    ->reverse()
    ->map(fn ($message) => [
        'role' => $message->is_assistant ? 'assistant' : 'user',
        'content' => $message->content,
    ])
    ->toArray();

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'messages' => $messages,
]);
```

## System messages

Pass instructions, persona, or context through the `system` parameter:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'system' => 'You are a helpful PHP expert. Answer concisely.',
    'messages' => [
        ['role' => 'user', 'content' => 'What is the null coalescing operator?'],
    ],
]);
```

The `system` parameter is separate from the `messages` array.

## Vision

Claude can read images alongside text. Pass an array of content blocks instead of a string:

```php
$imagePath = storage_path('app/photos/receipt.jpg');

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'What is in this image?'],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/jpeg',
                        'data' => base64_encode(file_get_contents($imagePath)),
                    ],
                ],
            ],
        ],
    ],
]);
```

Supported media types are `image/jpeg`, `image/png`, `image/gif`, and `image/webp`.

### Images from Laravel Storage

If you're using Laravel's Storage facade, read the file and encode it inline:

```php
use Illuminate\Support\Facades\Storage;

$file = Storage::disk('s3')->get('uploads/photo.jpg');
$mimeType = Storage::disk('s3')->mimeType('uploads/photo.jpg');

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Describe this photo.'],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mimeType,
                        'data' => base64_encode($file),
                    ],
                ],
            ],
        ],
    ],
]);
```

You can also pass images by URL:

```php
[
    'type' => 'image',
    'source' => ['type' => 'url', 'url' => 'https://example.com/photo.jpg'],
]
```

## Tracking users

Pass a `metadata.user_id` with each request to associate it with a user in your app. The ID shows up in the Anthropic Console for analytics and abuse detection:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'metadata' => [
        'user_id' => auth()->user()->uuid,
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);
```

Send an opaque ID, never an email or a name. For a signed-in user, a keyed hash of their ID keeps the Console's per-user view working without telling Anthropic who the user is (rotating `APP_KEY` gives everyone a new ID):

```php
'metadata' => [
    'user_id' => hash_hmac('sha256', (string) auth()->id(), config('app.key')),
],
```

## Long-running requests in queues

Message requests that use extended thinking, code execution, or large contexts can take 30+ seconds. In Laravel, push them to a queue so they don't block the request:

```php
class AskClaudeJob implements ShouldQueue
{
    public function __construct(public string $question, public int $userId) {}

    public function handle(): void
    {
        $response = Anthropic::messages()->create([
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 4096,
            'metadata' => ['user_id' => (string) $this->userId],
            'messages' => [
                ['role' => 'user', 'content' => $this->question],
            ],
        ]);

        Answer::create([
            'user_id' => $this->userId,
            'content' => collect($response->content)->firstWhere('type', 'text')?->text,
        ]);
    }
}
```

Bump `ANTHROPIC_REQUEST_TIMEOUT` in your `.env` to match the expected response time so the HTTP client doesn't cut the request short, and keep the queue connection's `retry_after` (in `config/queue.php`) above the job's `$timeout`. Otherwise the worker hands the job to another process while the first is still waiting on Claude, and you pay for the call twice.

The job reads the `text` block rather than `content[0]`: every Claude 5 model thinks by default, so a reply to a harder question can start with a `thinking` block.

## Handling refusals

When safety classifiers block a response, `stop_reason` comes back as `'refusal'` and `stop_details` explains why. The response is still a valid 200, so handle it like any branch in your controller or job:

```php
$response = Anthropic::messages()->create([...]);

if ($response->stop_reason === 'refusal') {
    Log::warning('Claude refused request', [
        'user_id' => auth()->id(),
        'category' => $response->stop_details->category,    // 'cyber', 'bio', 'frontier_llm', 'reasoning_extraction', 'general_harms', or null
        'explanation' => $response->stop_details->explanation,
    ]);

    return back()->with('error', 'Your request could not be processed.');
}
```

`stop_details` is `null` on normal completions, so only touch it when `stop_reason === 'refusal'`. Treat `category` as the machine-readable signal; the `explanation` text isn't stable between calls.

Sending the same conversation again usually gets another refusal, so drop or rephrase the turn that caused it before the user continues. A refusal while streaming arrives at the end of the stream, after some text may already be on screen; the [streaming page](./streaming.md#refusals-while-streaming) shows how to handle it.

### Falling back to another model

On Claude Fable 5 and Opus 5, the API can retry a refused request on another model instead of returning the refusal to you. It's in beta: pass `fallbacks` with the beta header, then log which model actually answered so your metrics don't lie about model usage:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-fable-5',
    'max_tokens' => 1024,
    'fallbacks' => 'default',
    'betas' => ['server-side-fallback-2026-07-01'],
    'messages' => [
        ['role' => 'user', 'content' => $prompt],
    ],
]);

foreach ($response->usage->iterations ?? [] as $iteration) {
    if ($iteration->type === 'fallback_message') {
        Log::info('Claude request served by fallback model', [
            'user_id' => auth()->id(),
            'model' => $iteration->model,
        ]);
    }
}
```

Where server-side fallback isn't available, list fallback models in `.env` and the package retries a refused request on them itself, in order, redeeming the refusal's fallback credit when it carries one (a Claude Haiku 5.5 refusal doesn't):

```env
ANTHROPIC_FALLBACK_MODELS=claude-opus-4-8
```

The list applies to every model your app calls, and each fallback has to be one the refused model allows: Claude Opus 4.8 or Opus 5 after Claude Fable 5 or Opus 5.5, Claude Sonnet 5 after Claude Sonnet 5.5 (the [fallback credit guide](https://platform.claude.com/docs/en/build-with-claude/fallback-credit) has the full list). Don't combine it with `fallbacks`: with the variable set, a request that passes `fallbacks` throws, so pick one. Streamed requests are left alone, and one call can now take a request timeout per model it tries, so size a queued job's `$timeout` for the chain.

Each request starts from the model you asked for. To keep a conversation on the model that answered, store it and ask for it next time:

```php
$response = Anthropic::messages()->create([
    'model' => $conversation->model ?? 'claude-fable-5',
    'max_tokens' => 1024,
    'messages' => $conversation->messages(),
]);

$conversation->update(['model' => $response->model]);
```

Each attempt is billed at its own model's rates, and `usage->iterations` is the per-attempt record. For the `fallback` content block, sticky routing, echo rules on later turns and the middleware's per-model settings, see the [refusal handling section in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/messages#falling-back-on-the-client).

## The `pause_turn` stop reason

Long-running turns can come back as `stop_reason: 'pause_turn'` instead of `'end_turn'`. Send the response back as the next assistant message to let Claude continue. This fits naturally in a queued job:

```php
class ContinueClaudeJob implements ShouldQueue
{
    public function handle(): void
    {
        $messages = $this->conversation->messagesArray();

        do {
            $response = Anthropic::messages()->create([
                'model' => 'claude-sonnet-5-5',
                'max_tokens' => 8192,
                'messages' => $messages,
            ]);

            $messages[] = [
                'role' => 'assistant',
                'content' => array_map(fn ($block) => $block->toArray(), $response->content),
            ];
        } while ($response->stop_reason === 'pause_turn');

        $this->conversation->persistFinalResponse($response);
    }
}
```

No special parameter is needed to resume; just echo the paused content back.

## Inference region

`usage->inferenceGeo` tells you which region handled the request, useful for residency logs or regional routing decisions:

```php
$response->usage->inferenceGeo; // 'us', 'eu', or null
```

## Compaction

For long conversations, the compaction betas let the API summarize earlier turns into a `compaction` block. Store the assistant content as `toArray()` returns it, which keeps the block's `signature`, and send it back unchanged:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-opus-5-5',
    'max_tokens' => 4096,
    'betas' => ['compact-2026-09-04'],
    'compaction' => ['type' => 'summarize'],
    'messages' => $conversation->messagesArray(),
]);

if ($response->stop_reason === 'compaction') {
    // One signed compaction block: it replaces the turns it summarized
    $conversation->update([
        'history' => [['role' => 'assistant', 'content' => $response->toArray()['content']]],
    ]);
} else {
    // No summary (max_tokens, refusal ...): keep the history as it is
    Log::warning('Claude compaction produced no summary', ['stop_reason' => $response->stop_reason]);
}
```

Cast `history` to `array` on the model. An edited block, or one with an extra key, returns a 400, and every later request needs the same beta header. The PHP docs cover [threshold compaction](https://mozex.dev/docs/anthropic-php/v1/usage/messages#compaction-at-a-threshold) and the placement rules.

## Passing any parameter

This package doesn't validate or transform request parameters. Anything you pass in the array goes directly to the Anthropic API. New API parameters work immediately, before the package adds explicit support:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'temperature' => 0.7,
    'stop_sequences' => ['```'],
    'messages' => [
        ['role' => 'user', 'content' => 'Write a haiku about PHP.'],
    ],
]);
```

The example uses Sonnet 4.6 because the Claude 5 models, Opus 4.8 and Opus 4.7 return a 400 for any non-default `temperature`, `top_p` or `top_k`, and API version `2026-10-21` rejects them on every model.

Mid-conversation `system` messages (instructions added partway through `messages`), fast mode and cache diagnostics also pass straight through; the PHP docs show each one.

---

For the full list of parameters, response fields, content block types, and the latest API changes, see the [Messages page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/messages) or the [Messages API reference](https://platform.claude.com/docs/en/api/messages/create) on the Anthropic docs.
