---
title: Messages
weight: 1
---

The Messages API is the primary way to interact with Claude. Send messages using the `Anthropic` Facade and get back typed response objects.

## Creating a message

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, world'],
    ],
]);
```

The response is a `CreateResponse` object with typed properties:

```php
$response->id;            // 'msg_01BSy0WCV7QR2adFBauynAX7'
$response->model;         // 'claude-sonnet-4-6'
$response->stop_reason;   // 'end_turn'
$response->content[0]->text; // 'Hello! How can I assist you today?'

$response->usage->inputTokens;  // 10
$response->usage->outputTokens; // 19
```

## Multi-turn conversations

The Anthropic API is stateless, so you pass the full conversation history on every request:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
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
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => $messages,
]);
```

## System messages

Pass instructions, persona, or context through the `system` parameter:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
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
    'model' => 'claude-sonnet-4-6',
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
    'model' => 'claude-sonnet-4-6',
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
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'metadata' => [
        'user_id' => auth()->user()->uuid,
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);
```

Use an opaque identifier (UUID, hash). Don't send names or email addresses.

## Long-running requests in queues

Message requests that use extended thinking, code execution, or large contexts can take 30+ seconds. In Laravel, push them to a queue so they don't block the request:

```php
class AskClaudeJob implements ShouldQueue
{
    public function __construct(public string $question, public int $userId) {}

    public function handle(): void
    {
        $response = Anthropic::messages()->create([
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'metadata' => ['user_id' => (string) $this->userId],
            'messages' => [
                ['role' => 'user', 'content' => $this->question],
            ],
        ]);

        Answer::create([
            'user_id' => $this->userId,
            'content' => $response->content[0]->text,
        ]);
    }
}
```

Bump `ANTHROPIC_REQUEST_TIMEOUT` in your `.env` to match the expected response time so the HTTP client doesn't cut the request short.

## Passing any parameter

This package doesn't validate or transform request parameters. Anything you pass in the array goes directly to the Anthropic API. New API parameters work immediately, before the package adds explicit support:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'temperature' => 0.7,
    'top_p' => 0.9,
    'stop_sequences' => ['```'],
    'messages' => [
        ['role' => 'user', 'content' => 'Write a haiku about PHP.'],
    ],
]);
```

---

For the full list of parameters, response fields, content block types, and the latest API changes, see the [Messages page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/messages) or the [Messages API reference](https://platform.claude.com/docs/en/api/messages/create) on the Anthropic docs.
