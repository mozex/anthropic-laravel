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

None of the three says Claude refused: the text stops, and the reply's `stop_reason` is `refusal`. Check it in `onMessage()` and mark the reply in your database, so the UI can drop it. With [`ANTHROPIC_FALLBACK_MODELS`](./messages.md) set, a refused stream goes on with the fallback instead, usually from the refused model's last word. When the API doesn't let the fallback carry on, its answer starts over after the partial one, and the browser gets both. The stream doesn't say which happened.

`toVercelStream()` sends text, reasoning, your own tool calls and web search sources, each page once. Server tools run on Anthropic's side, so their calls aren't sent. It takes an optional message ID, and the API's message ID is the default.

While events arrive that the browser isn't sent (shown thinking, a tool's input, a server tool's blocks), the event streams send a `: keep-alive` comment after 15 quiet seconds, which browsers and the AI SDK skip. That keeps a proxy's idle timeout (60 seconds in nginx, 100 at Cloudflare) from closing the stream. With `thinking.display: "omitted"`, nothing arrives at all while Claude thinks (39 seconds in one check), so nothing can be sent: set your proxy's read timeout (`fastcgi_read_timeout` or `proxy_read_timeout` in nginx) above your longest think. Behind nginx, the `X-Accel-Buffering: no` header the responses send keeps it from buffering the stream.

Under PHP-FPM, all three stop reading when the browser goes away, and a reply cut off that way never reaches `onMessage()`. Under Octane with Swoole or RoadRunner they don't. Swoole streams the reply but can't tell the browser left, so the reply runs to the end and is billed in full. RoadRunner holds a `StreamedResponse` until it's done, so the browser gets the reply in one piece. Raise Octane's `max_execution_time` if a long thinking stream can outlast its default 30 seconds.

## Broadcasting from a queued job

For a long answer, or a UI on Echo, stream from a queued job and broadcast the text as it comes:

```php
use Anthropic\Laravel\Facades\Anthropic;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldQueue;

class AskClaude implements ShouldQueue
{
    public function handle(): void
    {
        $message = Anthropic::messages()->createStreamed([
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 4096,
            'messages' => $this->conversation->messages()->orderBy('id')->get(['role', 'content'])->toArray(),
        ])->broadcastTo(new PrivateChannel("chat.{$this->conversation->id}"));

        $this->conversation->messages()->create([
            'role' => 'assistant',
            'content' => $message->toArray()['content'],
        ]);
    }
}
```

`broadcastTo()` sends these events:

| Event | Payload | When |
|-------|---------|------|
| `claude.delta` | `text`, `sequence` | The text since the last one, every 50 milliseconds (`everyMs:`), and before anything but text, such as a tool call |
| `claude.fallback` | `from`, `to` | A refused stream goes on with a fallback model |
| `claude.done` | `id`, `stop_reason` | The message ended |
| `claude.error` | `type`, `message` | The stream failed or was cut off. The exception is then thrown on, for the job to retry |

So a fast stream isn't a WebSocket frame per token. Each event's data stays under 6,000 bytes as Reverb receives it (`maxBytes:`), and a long run of text is cut between characters. Reverb's 10 KB limit covers the whole request, headers and channel names included, so keep `maxBytes` well under that. Every event goes out with `sendNow()`, so it isn't queued again. A broadcaster that fails is reported, the deltas after it are skipped and the stream is still read to its end, since the call is billed either way. The closing event is still tried. Tool calls and the whole message aren't broadcast: save the message `broadcastTo()` returns, and have the browser fetch it once `claude.done` arrives.

```js
useEcho(`chat.${id}`, ['.claude.delta', '.claude.fallback', '.claude.done', '.claude.error'], (event) => {
    // event.text and event.sequence; a sequence of 0 starts the reply over, as a retried job does
});
```

The names start with a dot because they aren't event classes. The 50 milliseconds are checked as each event arrives, so a stretch where nothing arrives (Claude thinking with its thinking omitted) sends nothing until the next one. `broadcastTo()` reads the stream to its end. To stop one partway, iterate `$stream->text()` yourself and `break`, as below.

## Livewire

`InteractsWithClaudeStreams` streams the text into a `wire:stream` target and returns the whole text, to save:

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Livewire\InteractsWithClaudeStreams;
use Livewire\Component;

class Chat extends Component
{
    use InteractsWithClaudeStreams;

    public string $answer = '';

    public function ask(): void
    {
        $this->answer = $this->streamClaude(Anthropic::messages()->createStreamed([...]), to: 'answer');
    }
}
```

```blade
<div wire:stream="answer">{{ $answer }}</div>
```

It calls Livewire's `stream()` with named arguments, the form Livewire 3 and 4 share. Its first update replaces what the element showed, so a second answer doesn't land on the first. With `replace: true`, every update holds the text so far instead of the new delta. Livewire's streaming doesn't work under Octane, and it holds a PHP-FPM worker for the whole reply, so for long answers broadcast from a queued job instead.

## Refusals while streaming

A refusal can come after part of the answer has already streamed, with the reply's `stop_reason` set to `'refusal'` and the reason in `stop_details`. `claude.done` carries the stop reason, so the browser can drop the partial reply when it's `refusal`, and `claude.fallback` says a fallback model took over. In the job, check the message `broadcastTo()` returns:

```php
use Illuminate\Support\Facades\Log;

$message = $stream->broadcastTo(new PrivateChannel("chat.{$this->conversation->id}"));

if ($message->stop_reason === 'refusal') {
    Log::warning('Claude refused mid-stream', [
        'conversation_id' => $this->conversation->id,
        'category' => $message->stop_details?->category,
    ]);

    return;
}
```

Before the user goes on, drop or rephrase the turn that caused the refusal: sending the same conversation again usually gets another one. With `ANTHROPIC_FALLBACK_MODELS` set, a refused stream goes on with a fallback model instead (see [Messages](./messages.md)).

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
