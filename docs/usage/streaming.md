---
title: Streaming
weight: 2
---

Streaming lets you receive Claude's response as it's generated. Perfect for chat UIs where you want to show text as it arrives.

## Basic streaming

```php
use Anthropic\Laravel\Facades\Anthropic;

$stream = Anthropic::messages()->createStreamed([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Tell me a short story.'],
    ],
]);

foreach ($stream as $response) {
    if ($response->type === 'content_block_delta'
        && $response->delta->type === 'text_delta') {
        echo $response->delta->text;
    }
}
```

Each iteration yields an event object. You'll see `message_start`, `content_block_start`, a series of `content_block_delta` events with text chunks, `content_block_stop`, and `message_delta`.

## Streaming to the browser with Laravel

Use a streamed response to push text chunks directly to the browser:

```php
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

Route::post('/chat', function () {
    $stream = Anthropic::messages()->createStreamed([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'messages' => request('messages'),
    ]);

    return new StreamedResponse(function () use ($stream) {
        foreach ($stream as $response) {
            if ($response->type === 'content_block_delta'
                && $response->delta->type === 'text_delta') {
                echo "data: " . json_encode(['text' => $response->delta->text]) . "\n\n";
                ob_flush();
                flush();
            }
        }
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ]);
});
```

On the frontend, consume it with `EventSource` or `fetch` with a reader.

## Streaming with Broadcasting

For apps with real-time UIs (Livewire, Inertia, Echo), push each chunk as a broadcast event:

```php
class AskClaudeJob implements ShouldQueue
{
    public function handle(): void
    {
        $stream = Anthropic::messages()->createStreamed([
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'messages' => $this->messages,
        ]);

        $fullText = '';

        foreach ($stream as $response) {
            if ($response->type === 'content_block_delta'
                && $response->delta->type === 'text_delta') {
                $fullText .= $response->delta->text;
                ChunkReceived::dispatch($this->conversationId, $response->delta->text);
            }
        }

        $this->message->update(['content' => $fullText, 'status' => 'finished']);
    }
}
```

## Stopping a stream mid-generation

If a user cancels, you can break out of the loop. A common pattern is to check a cache flag on each iteration:

```php
use Illuminate\Support\Facades\Cache;

foreach ($stream as $response) {
    if (Cache::pull("stop-stream-{$conversationId}")) {
        break;
    }

    if ($response->type === 'content_block_delta'
        && $response->delta->type === 'text_delta') {
        echo $response->delta->text;
    }
}
```

## Event types

| Event | When it fires | What it contains |
|-------|---------------|------------------|
| `message_start` | Once, at the start | Message envelope (id, model, role) and initial usage |
| `content_block_start` | Start of each content block | Block type and index |
| `content_block_delta` | Multiple times per block | Incremental content (text, JSON fragments, thinking) |
| `content_block_stop` | End of each content block | Block index |
| `message_delta` | Once, near the end | Stop reason and final usage |

The client handles `message_stop`, `ping`, and mid-stream errors internally. An error mid-stream throws an `ErrorException` (see [Error Handling](../reference/error-handling.md)).

---

For the full event sequence, streaming with tool use, streaming with thinking, and the complete delta type list, see the [Streaming page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/streaming) or the [Streaming guide](https://platform.claude.com/docs/en/build-with-claude/streaming) on the Anthropic docs.
