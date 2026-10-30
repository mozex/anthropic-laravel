---
title: Streaming
weight: 2
---

Streaming lets you receive Claude's response as it's generated. Perfect for chat UIs where you want to show text as it arrives.

## Basic streaming

```php
use Anthropic\Laravel\Facades\Anthropic;

$stream = Anthropic::messages()->createStreamed([
    'model' => 'claude-sonnet-5-5',
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
        'model' => 'claude-sonnet-5-5',
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
            'model' => 'claude-sonnet-5-5',
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

## Refusals while streaming

A refusal can come after part of the answer has already streamed. It arrives on the `message_delta` event, with `stop_reason` set to `'refusal'` and the reason in `delta->stop_details`. Treat the text you've streamed as incomplete, and tell the browser to drop it:

```php
use Illuminate\Support\Facades\Log;

foreach ($stream as $response) {
    if ($response->type === 'content_block_delta'
        && $response->delta->type === 'text_delta') {
        $fullText .= $response->delta->text;
        ChunkReceived::dispatch($this->conversationId, $response->delta->text);
    }

    if ($response->type === 'message_delta'
        && $response->delta->stop_reason === 'refusal') {
        Log::warning('Claude refused mid-stream', [
            'conversation_id' => $this->conversationId,
            'category' => $response->delta->stop_details?->category,
        ]);

        StreamRefused::dispatch($this->conversationId); // the UI clears the partial reply
        $this->message->update(['content' => null, 'status' => 'refused']);

        return;
    }
}
```

`StreamRefused` is your own broadcast event, like `ChunkReceived`. Before the user goes on, drop or rephrase the turn that caused the refusal: sending the same conversation again usually gets another one.

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
| `message_delta` | Once, near the end | Stop reason, `stop_details` on a refusal, the code execution `container`, and final usage |

The client skips `ping` and ends the loop at `message_stop`. Any other event type reaches your loop, including ones the API adds later, so check `$event->type` rather than assuming this table is complete. An error mid-stream throws an `ErrorException` (see [Error Handling](../reference/error-handling.md)).

Some blocks arrive whole in `content_block_start` with no deltas: an on-demand `compaction` block (its `content` is the summary string), `fallback` and `advisor_tool_result`.

---

For the full event sequence, streaming with tool use, streaming with thinking, and the complete delta type list, see the [Streaming page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/streaming) or the [Streaming guide](https://platform.claude.com/docs/en/build-with-claude/streaming) on the Anthropic docs.
