---
name: anthropic-laravel
description: Use when writing Laravel code that calls the Anthropic Claude API through the `mozex/anthropic-laravel` package. Trigger on any mention of the `Anthropic` facade, `anthropic-laravel`, `Anthropic::messages()`, `Anthropic::batches()`, `Anthropic::models()`, `Anthropic::fake()`, `CreateResponse`, `ErrorException`, or any Claude/Anthropic API work in a Laravel project. Also trigger on requests to wire Claude into a Laravel app for chat, streaming responses, function calling (tool use), extended thinking, web search, code execution, document citations, bulk processing with batches, token counting before sending, or testing code that calls the API. This skill covers the Laravel wrapper plus the underlying `mozex/anthropic-php` SDK response shapes and conventions, so it's the right one to use whether the user is writing a controller, a queued job, a Livewire component, or direct PHP SDK code in a Laravel project. For exhaustive reference material (every content block type, every capability flag, every result block variant), fetch Context7 libraries `/mozex/anthropic-laravel` or `/mozex/anthropic-php` on demand rather than guessing.
---

# Anthropic Laravel

A Laravel wrapper around the `mozex/anthropic-php` SDK. It adds a Facade, a publishable config, an install command, and a `Facade::swap()`-based testing fake. Every real call is handed off to the PHP client, so the response DTOs, content block shapes, and streaming events below come straight from the underlying SDK.

## Quick reference

This skill covers the Laravel integration plus the common paths through the SDK. For depth on any specific topic, fetch on demand rather than guessing.

**Preferred: Context7** (when MCP or CLI is configured)

- Laravel wrapper docs: library ID `/mozex/anthropic-laravel`
- Underlying PHP SDK docs: library ID `/mozex/anthropic-php`

**Fallback: direct URL fetch** (when Context7 isn't installed)

The docs site serves AI-friendly markdown for every page. Start at the introduction URL; it returns a markdown table of contents listing every page with its full URL, so one fetch tells you what's available without needing to know the page paths upfront.

- Laravel introduction (with TOC): https://mozex.dev/docs/anthropic-laravel/v1
- PHP SDK introduction (with TOC): https://mozex.dev/docs/anthropic-php/v1
- Anthropic API reference: https://platform.claude.com/docs/en/api

Good things to fetch rather than guess: the full list of content block types, every capability flag on a model, all server tool result block variants, the complete HTTP error status table, every response fake class, the exact shape of obscure streaming delta types. Those are stable reference material where a wrong guess will produce subtle bugs.

## Setup

```bash
composer require mozex/anthropic-laravel
php artisan anthropic:install
```

The install command publishes `config/anthropic.php` and appends `ANTHROPIC_API_KEY=` to `.env`. Two keys, both env-backed:

```php
return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'request_timeout' => env('ANTHROPIC_REQUEST_TIMEOUT', 30),
];
```

If `ANTHROPIC_API_KEY` is missing when the client resolves, the deferred service provider throws `Anthropic\Laravel\Exceptions\ApiKeyIsMissing`. The binding is a singleton, so `Anthropic::messages()`, `app('anthropic')`, and `app(\Anthropic\Contracts\ClientContract::class)` all resolve the same instance.

Raise `ANTHROPIC_REQUEST_TIMEOUT` to 60+ seconds for extended thinking on high/max effort, code execution with slow scripts, or very large contexts. Streaming only applies the timeout to the initial connection; chunks flow freely after that.

## The Facade

`Anthropic\Laravel\Facades\Anthropic` is the only entry point for application code:

```php
use Anthropic\Laravel\Facades\Anthropic;

Anthropic::messages();     // Messages resource
Anthropic::batches();      // Message Batches
Anthropic::models();       // Models
Anthropic::completions();  // Legacy Text Completions
```

Don't instantiate `Anthropic\Client` directly in Laravel code; the container binding already does that. If you need a custom HTTP client, headers, or a non-standard base URI, see the factory section at the end.

## Five things that surprise people

These are the pitfalls that actually cause bugs. Internalize them before writing code against the SDK.

### 1. Property casing is inconsistent by design

DTO casing matches the wire format from the API, so different response shapes use different conventions. Don't normalize.

| DTO | Casing | Example |
|-----|--------|---------|
| `CreateResponse` top level | snake_case | `$r->stop_reason`, `$r->stop_sequence` |
| `CreateResponseUsage` (nested) | camelCase | `$r->usage->inputTokens`, `$r->usage->cacheCreationInputTokens`, `$r->usage->inferenceGeo`, `$r->usage->serviceTier` |
| Content blocks | snake_case | `$block->type`, `$block->tool_use_id`, `$block->partial_json` |
| Stream event DTOs | snake_case | `$event->content_block_start`, `$event->delta` |
| `CountTokensResponse` | camelCase | `$r->inputTokens` |
| Batch / Models responses | camelCase | `$r->processingStatus`, `$r->requestCounts`, `$r->displayName`, `$r->maxInputTokens` |

When in doubt, `dump($response)` or consult `/mozex/anthropic-php` via Context7 or the docs site. Guessing usually produces `PropertyNotFound` errors at runtime.

### 2. The `caller` filter on tool dispatchers

Every `tool_use` block carries a `caller` object. When you mix custom tools with server tools (notably code execution), Claude can call your tools *indirectly* from inside the sandbox. Those calls have already been handled on Anthropic's side. Running them again in your dispatcher duplicates the work and can corrupt state.

Always filter by `$block->caller?->type === 'direct'`:

```php
foreach ($response->content as $block) {
    if ($block->type === 'tool_use' && $block->caller?->type === 'direct') {
        $service = app($toolRegistry[$block->name]);
        $results[] = [
            'type' => 'tool_result',
            'tool_use_id' => $block->id,
            'content' => json_encode($service->handle($block->input)),
        ];
    }
}
```

Indirect `caller->type` is a versioned string like `'code_execution_20250825'` or `'code_execution_20260120'`. Skip those blocks; they're informational.

### 3. `pause_turn` is not an error

Long-running turns can return `stop_reason: 'pause_turn'` instead of `'end_turn'`. The response is valid and complete for the work done so far. To resume, echo the content back as the next assistant message. No special parameter needed.

```php
do {
    $response = Anthropic::messages()->create([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 8192,
        'messages' => $messages,
    ]);

    $messages[] = [
        'role' => 'assistant',
        'content' => array_map(fn ($block) => $block->toArray(), $response->content),
    ];
} while ($response->stop_reason === 'pause_turn');
```

This pattern fits naturally inside a queued job where each iteration can take a while.

### 4. Refusals return HTTP 200

When safety classifiers intervene, `stop_reason` is `'refusal'` and `stop_details` is populated. The HTTP response is still 200, and no exception is thrown. Branch on `stop_reason` explicitly:

```php
if ($response->stop_reason === 'refusal') {
    Log::warning('Claude refused', [
        'user_id' => auth()->id(),
        'category' => $response->stop_details->category,    // 'cyber', 'bio', or null
        'explanation' => $response->stop_details->explanation,
    ]);
    return back()->with('error', 'Your request could not be processed.');
}
```

`stop_details` is `null` on normal completions, so guard before reading it. Treat `category` as the machine-readable signal; `explanation` text isn't stable between calls, so don't parse it.

### 5. Mid-stream errors arrive after HTTP 200

Streamed responses started successfully before the error happened, so the status code is 200, but the client still throws `ErrorException` when the failing event arrives. Always wrap the iteration:

```php
try {
    foreach ($stream as $event) { /* ... */ }
} catch (\Anthropic\Exceptions\ErrorException $e) {
    // log, retry, or surface to the user
}
```

## Messages API

`Anthropic::messages()->create(...)` is the main call. At minimum: `model`, `max_tokens`, and a non-empty `messages` array. Everything is pass-through: the array ships to the API as JSON with no validation, no coercion, no casing rewrites. New API parameters work the day Anthropic ships them.

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, world'],
    ],
]);

$response->id;
$response->stop_reason;
$response->content[0]->text;
$response->usage->inputTokens;
```

Messages alternate `user` and `assistant`, starting with `user`. `system` is a *separate top-level string*, not a message with `role: system`.

### Multi-turn

The API is stateless, so send the full history every request. Typical Laravel pattern pulling from Eloquent:

```php
$messages = $conversation->messages()
    ->latest('id')->take(20)->get()->reverse()
    ->map(fn ($m) => ['role' => $m->is_assistant ? 'assistant' : 'user', 'content' => $m->content])
    ->toArray();
```

### Vision

Pass `content` as an array of content blocks. Supported media types: `image/jpeg`, `image/png`, `image/gif`, `image/webp`. Common pattern with `Storage`:

```php
use Illuminate\Support\Facades\Storage;

['type' => 'image', 'source' => [
    'type' => 'base64',
    'media_type' => Storage::disk('s3')->mimeType('uploads/photo.jpg'),
    'data' => base64_encode(Storage::disk('s3')->get('uploads/photo.jpg')),
]]
```

Or by URL: `['type' => 'image', 'source' => ['type' => 'url', 'url' => '...']]`. Multiple images in one message work; add more blocks.

### User tracking

Pass an opaque identifier (UUID, hash) via `metadata.user_id`. It surfaces in the Anthropic Console for analytics and abuse detection. Never names or emails.

```php
'metadata' => ['user_id' => auth()->user()->uuid],
```

### Long requests belong in queues

Anything with extended thinking, code execution, or large context pushes past typical HTTP timeouts. Dispatch to a queue and raise both `ANTHROPIC_REQUEST_TIMEOUT` and the job's `$timeout`.

## Streaming

`createStreamed()` takes the same shape as `create()` and returns an iterable `StreamResponse`. The client handles `message_stop` and `ping` events internally; your loop sees five event types:

- `message_start`: full envelope plus initial usage
- `content_block_start`: `$event->index`, `$event->content_block_start->type`
- `content_block_delta`: `$event->delta->type` is `text_delta`, `input_json_delta`, `thinking_delta`, `signature_delta`, or `citations_delta`
- `content_block_stop`: `$event->index`
- `message_delta`: final `stop_reason` and usage

```php
foreach ($stream as $event) {
    if ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta') {
        echo $event->delta->text;
    }
}
```

### To the browser

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

return new StreamedResponse(function () use ($stream) {
    foreach ($stream as $event) {
        if ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta') {
            echo 'data: ' . json_encode(['text' => $event->delta->text]) . "\n\n";
            ob_flush();
            flush();
        }
    }
}, 200, [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache',
    'X-Accel-Buffering' => 'no', // disables nginx buffering
]);
```

Without `X-Accel-Buffering: no`, chunks arrive in bursts behind nginx.

### Broadcasting and cancel

Dispatch a `ChunkReceived` event per `text_delta` for Livewire/Inertia/Echo UIs. To cancel mid-generation, check a `Cache::pull("stop-stream-{$id}")` flag each iteration and `break`.

`$stream->meta()` returns the same `MetaInformation` object as on a regular response; headers arrive at the start of the stream.

## Tool use

Define tools with JSON Schema `input_schema`. Write clear descriptions: Claude uses them to pick the right tool and the right arguments. Add `'strict' => true` to guarantee schema-conformant output.

```php
'tools' => [[
    'name' => 'get_weather',
    'description' => 'Get the current weather in a given location',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City and state, e.g. San Francisco, CA'],
        ],
        'required' => ['location'],
    ],
]]
```

Reading the call:

```php
$block = $response->content[1];
$block->type;   // 'tool_use'
$block->id;     // 'toolu_...'
$block->name;
$block->input;  // array
$response->stop_reason; // 'tool_use'
```

Send results back as a new `user` turn containing `tool_result` blocks. `tool_use_id` must match the `id` from the call. Set `'is_error' => true` on failures.

`tool_choice` variants: `['type' => 'auto']` (default), `['type' => 'any']`, `['type' => 'tool', 'name' => '...']`, `['type' => 'none']`. Only `auto` and `none` work alongside `thinking`.

See pitfall #2 above for the critical `caller->type === 'direct'` filter.

## Extended thinking

Two modes:

- **Adaptive** (Opus 4.6, Sonnet 4.6): `'thinking' => ['type' => 'adaptive']`. Use `'output_config' => ['effort' => 'low'|'medium'|'high'|'max']` to guide depth. `max` is Opus 4.6 / Sonnet 4.6 only.
- **Budget-based** (older models): `'thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]`. Deprecated on 4.6.

Response content may include `thinking` (with `signature`), `redacted_thinking` (opaque `data`), and `text` blocks. With `'display' => 'omitted'`, the `thinking` field is empty but `signature` is still present, which is faster and preserves multi-turn continuity.

Preserve `signature` verbatim. The server uses it to verify thinking integrity on follow-up turns. When doing multi-turn with thinking, pass the full content array (including thinking blocks) back as the assistant message: `array_map(fn ($b) => $b->toArray(), $response->content)`.

Interleaved thinking with tools activates automatically when you combine `thinking` (adaptive) and `tools` in the same request.

## Server tools

These run on Anthropic infrastructure; add to `tools` and results come back in the response.

**Web search**: `['type' => 'web_search_20250305', 'name' => 'web_search']`. Options: `max_uses`, `allowed_domains`, `blocked_domains`, `user_location`. Version `web_search_20260209` adds dynamic filtering and requires code execution enabled alongside.

**Code execution**: `['type' => 'code_execution_20250825', 'name' => 'code_execution']`. Version `code_execution_20260120` adds REPL state persistence and programmatic tool calling.

**Container persistence**: the sandbox lives 30 days. Reuse it by passing top-level `'container' => $response->container['id']` on follow-ups. Store the ID on your Eloquent model.

**`container_upload` blocks**: when Claude writes a file inside the container, a block with `file_id` appears. Pair with the Files API to download and forward.

**Server tool errors still return HTTP 200**. The error is inside the result block's `content`: `$block->content['type']` ends in `_error`, `$block->content['error_code']` gives the reason.

Usage counts: `$response->usage->serverToolUse?->webSearchRequests` / `webFetchRequests` / `codeExecutionRequests` / `toolSearchRequests`.

For the full list of result block types (`web_search_tool_result`, `bash_code_execution_tool_result`, `text_editor_code_execution_tool_result`, etc.) and the tool version history, fetch `/mozex/anthropic-php` via Context7 or the docs site.

## Citations

Enable per document content block with `'citations' => ['enabled' => true]`. The response splits into multiple text blocks; cited claims have a `citations` array, linking phrases don't.

Five citation types: `char_location`, `page_location`, `content_block_location`, `web_search_result_location`, `search_result_location`. All include `cited_text`. Document-based types include `document_index` (zero-indexed, matching the order you passed documents) and `document_title`.

Web search citations are automatic; you don't enable them explicitly. Streaming delivers citations as `citations_delta` events on the delta object.

For per-type field lists, fetch `/mozex/anthropic-php` via Context7 or the docs site.

## Token counting

Same shape as `create()`. Returns a single `inputTokens` field (camelCase). Include `tools` and `system` if they'll be in the real request, since both contribute to the count. Common use: trim conversation history before hitting context limits.

```php
$count = Anthropic::messages()->countTokens(['model' => $model, 'messages' => $messages]);
if ($count->inputTokens > 180000) { $messages = array_slice($messages, 4); }
```

## Models

```php
$response = Anthropic::models()->list();

foreach ($response->data as $model) {
    $model->id;              // 'claude-sonnet-4-6'
    $model->displayName;
    $model->maxInputTokens;  // context window
    $model->maxTokens;       // max output
    $model->capabilities->imageInput->supported;
    $model->capabilities->thinking->types->adaptive->supported;
    $model->capabilities->effort->max->supported;
}
```

Pagination: `limit` (default 20, max 1000), `after_id`, `before_id`. Each list item is a full `RetrieveResponse`.

Cache the list so you're not hitting the API per request:

```php
$models = Cache::remember('anthropic.models', now()->addHour(), fn () => Anthropic::models()->list()->data);
```

The full capability tree (batch, citations, codeExecution, imageInput, pdfInput, structuredOutputs, thinking types, effort levels, and the versioned contextManagement strategies map) is extensive. Fetch `/mozex/anthropic-php` via Context7 or the docs site for the exact shape.

## Batches

50% of normal cost, up to 24 hours to complete. Each request needs a `custom_id` (your own string) and `params` matching a `messages()->create()` shape.

```php
Anthropic::batches()->create(['requests' => [
    ['custom_id' => 'r1', 'params' => [/* messages create params */]],
]]);
Anthropic::batches()->retrieve($id);
Anthropic::batches()->list(['limit' => 10]);
Anthropic::batches()->cancel($id);
Anthropic::batches()->delete($id); // only after ended
```

Three `processingStatus` values only: `in_progress`, `canceling`, `ended`. **Canceled batches end with `ended`, not `canceled`**. The per-request breakdown lives in `$batch->requestCounts->{processing, succeeded, errored, canceled, expired}`.

Polling pattern: store `anthropic_batch_id` on a model, run `$schedule->command('batches:poll')->everyFiveMinutes()`, dispatch a `ProcessBatchResultsJob` when status flips to `ended`.

Results stream JSONL; iterate rather than buffering:

```php
foreach (Anthropic::batches()->results($id) as $individual) {
    if ($individual->result->type === 'succeeded') {
        $message = $individual->result->message; // full CreateResponse shape
        // ...
    }
    if ($individual->result->type === 'errored') {
        Log::error('Batch request failed', ['custom_id' => $individual->customId]);
    }
}
```

## Error handling

Exception hierarchy:

```
ErrorException          (any 4xx/5xx API response)
└── RateLimitException  (HTTP 429)
TransporterException    (network, DNS, connection)
UnserializableResponse  (response wasn't valid JSON)
ApiKeyIsMissing         (Laravel wrapper; config-time)
```

On `ErrorException`: `$e->getMessage()`, `$e->getErrorType()` (e.g., `'invalid_request_error'`, `'rate_limit_error'`, `'overloaded_error'`), `$e->getStatusCode()`, `$e->response` (PSR-7).

Catch the specific subclass first, since `RateLimitException extends ErrorException`:

```php
try {
    Anthropic::messages()->create([...]);
} catch (RateLimitException $e) {
    sleep((int) $e->response->getHeaderLine('Retry-After'));
} catch (ErrorException $e) {
    // other API errors
} catch (TransporterException $e) {
    // network failure; original in getPrevious()
}
```

### Queue retry pattern

Release the job on rate limits and 529 overloads instead of throwing. Overload errors are expected during peak and shouldn't page an on-call engineer.

```php
class AskClaudeJob implements ShouldQueue {
    public $tries = 5;
    public $backoff = [10, 30, 60, 120, 300];

    public function handle(): void {
        try {
            Anthropic::messages()->create([...]);
        } catch (RateLimitException $e) {
            $this->release((int) $e->response->getHeaderLine('Retry-After') ?: 60);
            return;
        } catch (ErrorException $e) {
            if ($e->getStatusCode() === 529) { $this->release(30); return; }
            throw $e;
        }
    }
}
```

### Silencing noise

Drop specific errors from the error tracker via `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(function (\Anthropic\Exceptions\ErrorException $e) {
        if ($e->getStatusCode() === 529) return false;
    });
})
```

Returning `false` stops default reporting. Returning nothing falls through. Extend for any status you want quiet.

For the full HTTP status code table and the underlying transport details, fetch `/mozex/anthropic-laravel` or `/mozex/anthropic-php` via Context7 or the docs site.

## Meta information

`$response->meta()`, `$stream->meta()`, `$results->meta()` all return `MetaInformation`:

```php
$meta = $response->meta();
$meta->requestId;                                 // log this with every request
$meta->requestLimit->limit / ->remaining / ->reset;  // ISO 8601
$meta->tokenLimit->...;
$meta->inputTokenLimit->...;
$meta->outputTokenLimit->...;
$meta->priorityInputTokenLimit?->...;   // null unless on Priority Tier
$meta->priorityOutputTokenLimit?->...;
```

Throttle before you hit 429 by stashing a cache flag when remaining drops low:

```php
if ($meta->requestLimit->remaining < 10) {
    Cache::put('anthropic.throttle', true, Carbon::parse($meta->requestLimit->reset));
}
```

Include `requestId` in log context on every response; it's what Anthropic support will ask for.

## Testing

`Anthropic::fake()` swaps the real client via `Facade::swap()`. Everything resolved through the Facade or container picks up the fake.

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Resources\Messages;
use Anthropic\Responses\Messages\CreateResponse;

Anthropic::fake([
    CreateResponse::fake(['content' => [['type' => 'text', 'text' => 'Paris.']]]),
]);

// run code...

Anthropic::assertSent(Messages::class, function (string $method, array $parameters): bool {
    return $method === 'create' && $parameters['model'] === 'claude-sonnet-4-6';
});
```

Every response class has `::fake()` with sensible defaults. Overrides merge recursively, so you can change one nested field without rewriting the parent. Pass `ErrorException` instances as fakes to test error branches. Pass a file resource (or `php://memory` stream) to `CreateStreamedResponse::fake()` for streaming tests.

Assertions: `Anthropic::assertSent($resource, $callbackOrCount)`, `assertNotSent`, `assertNothingSent`. Also resource-level: `Anthropic::messages()->assertSent(...)`.

Test queued jobs with `$job::dispatchSync()` plus `Anthropic::fake()`.

Running out of fakes throws "No fake responses left." Use `$fake->addResponses([...])` to add mid-test.

For the full list of fake response classes (`Messages\CountTokensResponse`, `Batches\BatchResultResponse`, `Models\ListResponse`, etc.), the in-memory SSE stream helper, and recursive-merge semantics for nested overrides, fetch `/mozex/anthropic-laravel` via Context7 or the docs site.

## Direct SDK factory (rare)

Only when you need a custom HTTP client, base URI, headers, or stream handler:

```php
$client = Anthropic::factory()
    ->withApiKey(config('anthropic.api_key'))
    ->withBaseUri('proxy.example.com/v1')
    ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 120]))
    ->withHttpHeader('X-Custom', 'value')
    ->withQueryParam('region', 'eu')
    ->withStreamHandler($handler) // required for PSR-18 clients other than Guzzle / Symfony
    ->make();
```

To use this as the app-wide client, rebind `Anthropic\Contracts\ClientContract` in a service provider.
