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

foreach ($stream->text() as $delta) {
    echo $delta;
}

$message = $stream->finalMessage(); // the whole response, as create() would return it
```

Iterating `$stream` itself yields every event object: `message_start`, `content_block_start`, a series of `content_block_delta` events, `content_block_stop`, and `message_delta`. `text()` picks out the text deltas, and `finalMessage()` reads to the end and returns the message with every block filled in.

## Streaming to the browser

Return the stream from a route through one of three responses, each matching a frontend:

| Response | Frontend | Format |
|----------|----------|--------|
| `toEventStream()` | `useEventStream` from `@laravel/stream-react`, `-vue` or `-svelte` | Server-sent `update` events, ending in `</stream>` |
| `toTextStream()` | `useStream` from the same packages | The plain text |
| `toVercelStream()` | `useChat` from the AI SDK | The AI SDK's UI message stream (v1) |

They're on every stream from the facade, a connection or `Anthropic::fake()`. An injected `ClientContract` is the PHP client underneath, so its streams don't have them: inject `Anthropic\Laravel\AnthropicManager` instead, or call `->as(\Anthropic\Laravel\Responses\StreamResponse::class)` on the stream before reading it.

`useEventStream` opens an `EventSource`, which only sends GET requests. So store the user's message with a POST, then stream the reply from a GET route:

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Messages\CreateResponse;
use App\Models\Conversation;

Route::post('/conversations/{conversation}/messages', function (Conversation $conversation) {
    $conversation->messages()->create(['role' => 'user', 'content' => request('content')]);

    return response()->noContent();
});

Route::get('/conversations/{conversation}/reply', function (Conversation $conversation) {
    return Anthropic::messages()->createStreamed([
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 1024,
        'messages' => $conversation->messages()->orderBy('id')->get(['role', 'content'])->toArray(),
    ])
        // content has an array cast, so a reply's blocks go back as they came
        ->onMessage(fn (CreateResponse $message) => $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $message->toArray()['content'],
        ]))
        ->toEventStream();
});
```

Each time the page opens that GET route, Claude is called and billed, a reload included. So stream only when the last stored message is the user's. `useStream` and `useChat` POST to their route instead, so `toTextStream()` and `toVercelStream()` go on a `Route::post`. `useChat` sends its own message parts, which you turn into Anthropic's `messages`.

On the frontend, pass `glue: ""`. The hook joins the parts it gets with a space by default, so "Hel" and "lo" would show as "Hel lo":

```js
const { message } = useEventStream(`/conversations/${id}/reply`, { glue: '' });
```

`toEventStream()` puts every line of a delta on its own `data:` line, so a newline in Claude's answer arrives as a newline. If the stream fails, or ends before Claude's message does, that's reported and an `error` event goes out. It carries the API's error type and message, never another exception's message. The hook passes that event to your `onError` (its JSON in `event.data`) and closes, so `onComplete` doesn't run. The `</stream>` marker follows, unless PHP itself dies first. That matters: a plain `EventSource` reconnects when a stream just stops, which runs the route again and bills the call twice. `toTextStream()` has no way to say it failed: it just ends.

None of the three says Claude refused: the text stops, and the reply's `stop_reason` is `refusal`. Check it in `onMessage()` and mark the reply in your database, so the UI can drop it.

`toVercelStream()` sends text, reasoning, your own tool calls and web search sources, each page once. Server tools run on Anthropic's side, so their calls aren't sent. It takes an optional message ID, and the API's message ID is the default.

While events arrive that the browser isn't sent (shown thinking, a tool's input, a server tool's blocks), the event streams send a `: keep-alive` comment after 15 quiet seconds, which browsers and the AI SDK skip. That keeps a proxy's idle timeout (60 seconds in nginx, 100 at Cloudflare) from closing the stream. With `thinking.display: "omitted"`, nothing arrives at all while Claude thinks (39 seconds in one check), so nothing can be sent: set your proxy's read timeout (`fastcgi_read_timeout` or `proxy_read_timeout` in nginx) above your longest think. Behind nginx, the `X-Accel-Buffering: no` header the responses send keeps it from buffering the stream.

Under PHP-FPM, all three stop reading when the browser goes away, and a reply cut off that way never reaches `onMessage()`. Under Octane with Swoole or RoadRunner they don't. Swoole streams the reply but can't tell the browser left, so the reply runs to the end and is billed in full. RoadRunner holds a `StreamedResponse` until it's done, so the browser gets the reply in one piece. Raise Octane's `max_execution_time` if a long thinking stream can outlast its default 30 seconds.

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

For `currentMessage()`, the callbacks, the full event sequence, streaming with tool use and thinking, and the complete delta type list, see the [Streaming page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/streaming) or the [Streaming guide](https://platform.claude.com/docs/en/build-with-claude/streaming) on the Anthropic docs.
