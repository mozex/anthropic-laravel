---
title: Batches
weight: 9
---

Message Batches let you send large volumes of message requests asynchronously at 50% of the normal cost. Results are available within 24 hours. A great fit for bulk processing jobs in Laravel.

## Creating a batch

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::batches()->create([
    'requests' => [
        [
            'custom_id' => 'request-1',
            'params' => [
                'model' => 'claude-sonnet-5-5',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => 'What is the capital of France?'],
                ],
            ],
        ],
        // ... one entry per request, each with its own custom_id
    ],
]);

$response->id;                        // 'msgbatch_04Rka1yCsMLGPnR7kfPdgR8x'
$response->processingStatus;          // 'in_progress'
$response->requestCounts->processing; // 1
$response->createdAt;                 // '2026-04-14T12:00:00Z'
$response->expiresAt;                 // '2026-04-15T12:00:00Z'
```

The `params` object inside each request takes the same parameters you'd pass to `Anthropic::messages()->create()`. Tool use, thinking, and system messages all work.

## Sending a batch and handling it when it ends

`Anthropic::batch()` takes the same list of requests as `create()` above. It creates the batch and queues a job that polls it until it ends, then runs your `then()` callback with the ended batch:

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Batches\BatchResponse;

$report = Report::create(['status' => 'processing']);

$batch = Anthropic::batch($requests)
    ->then(function (BatchResponse $batch) use ($report) {
        $report->update(['status' => 'done']);
    })
    ->catch(function (Throwable $e) use ($report) {
        $report->update(['status' => 'failed']);
    })
    ->onQueue('batches')
    ->every(120)
    ->dispatch();

$report->update(['anthropic_batch_id' => $batch->id]);
```

`dispatch()` sends the batch right away and returns it, so you can store its ID. The poll job first checks it after `every()` seconds (60 by default, at most 15 minutes), then waits twice as long each time, up to 15 minutes (or `every()` if that's longer), so a batch that takes the full day costs about a hundred polls. It releases itself until the batch ends, whether its requests succeeded, errored, were canceled or expired. A rate limit, an overload or a dropped connection only delays the next poll. `catch()` runs when polling fails for good: a batch that no longer exists, a refused key, a `then()` callback that threw, or the job timing out or losing the connection while it reads the results, by when some `BatchResultReceived` events already went out. `onConnection()` picks the queue connection, as on any job. `timeout(600)` gives the poll job longer than the worker's `--timeout` for a big results file; keep the connection's `retry_after` above it, or a second worker takes the job while the first still reads.

The callbacks run inside the queued job, so they're serialized like Laravel's own batch callbacks: don't use `$this` in them, and pass what they need through `use` (a model is stored by its key and fetched again), or use an invokable class. A closure is signed with your `APP_KEY`, so rotating the key while a batch is in flight fails its job without running `catch()`; an invokable class has no signature to break.

The poll job needs a worker. On a `sync`, `deferred` or `background` queue nothing could poll the batch a second time, so `dispatch()` throws a `LogicException` before anything is sent, as it does for a queue connection that isn't defined. Under `Anthropic::fake()` it runs on `sync` like any job in your tests (see [Testing batches](../reference/testing.md#testing-batches)).

### Handling each result

When the batch ends, the poll job reads its results once and dispatches a `BatchResultReceived` event for each one, then `BatchEnded`, then runs `then()`. A queued listener handles each result in its own job:

```php
use Anthropic\Laravel\Events\BatchResultReceived;
use Illuminate\Contracts\Queue\ShouldQueue;

class StoreBatchAnswer implements ShouldQueue
{
    public function handle(BatchResultReceived $event): void
    {
        if ($event->result['type'] !== 'succeeded') {
            Log::warning('Batch request failed', ['custom_id' => $event->customId, 'result' => $event->result]);

            return;
        }

        Answer::updateOrCreate(['request_id' => $event->customId], [
            'content' => collect($event->result['message']['content'])->firstWhere('type', 'text')['text'] ?? null,
        ]);
    }
}
```

`$event->result` is the result as the API sent it: a `message` for `succeeded`, an `error` for `errored`. Each event also carries the batch ID and the connection's name. Because the results are read once, here, `then()` gets the batch without its results; read them in the listener rather than fetching them again. A worker that dies while reading them runs the job again, and the events with it, so keep the listener safe to repeat, as `updateOrCreate()` is.

### Telling someone it's done

`BatchSubmitted` fires when the batch is sent and `BatchEnded` when it ends, each with the connection's name, the batch ID and the batch as an array:

```php
use Anthropic\Laravel\Events\BatchEnded;

Event::listen(function (BatchEnded $event) {
    $report = Report::firstWhere('anthropic_batch_id', $event->id);

    $report->user->notify(new ReportReady($report, $event->batch['request_counts']));
});
```

### Per connection and per tenant

A batch belongs to the workspace of the key that created it, so it's polled through the same connection:

```php
Anthropic::connection('support')->batch($requests)->dispatch();

Anthropic::withApiKey($team->anthropic_key)->batch($requests)->dispatch();
```

The poll job polls as the batch was sent: with a `withApiKey()` key, and with the options of a `withOptions()` call (a team's workspace, say). Both are encrypted under your `APP_KEY` inside the job, and a key's job (`PollBatchWithKey`) is a `ShouldBeEncrypted` job as well, so neither sits in plain text in the queue, `failed_jobs` or Telescope's job entries. A named connection's job carries only its name.

### The poll job on your queue

There's one poll job per batch (`ShouldBeUnique` on the batch ID): a second one for the same batch isn't queued. It's tagged `anthropic` and `anthropic:batch:{id}` for Horizon, and stops an hour or more after the batch's 24 hours run out. On SQS, every poll counts toward a redrive policy's `maxReceiveCount`, so set it above 120 for the poll job's queue, or a long batch's job lands in the dead-letter queue without `catch()`. Each poll is a short job, so a busy app may want them out of Horizon's completed list:

```php
// config/horizon.php
'silenced' => [
    \Anthropic\Laravel\Batches\PollBatch::class,
    \Anthropic\Laravel\Batches\PollBatchWithKey::class,
],
```

Laravel 13: `Queue::route(PollBatch::class, connection: 'redis', queue: 'batches')` sends every poll job to one place without `onQueue()` on each batch.

## Checking batches from the command line

```bash
php artisan anthropic:batches                       # the latest 20, with their request counts
php artisan anthropic:batches msgbatch_04Rka1yCsMLGPnR7kfPdgR8x
php artisan anthropic:batches --connection=support --limit=50
```

## Polling on a schedule instead

If you'd rather keep batches in your own table, store the ID and check them from a scheduled command:

```php
// routes/console.php
Schedule::call(function () {
    Report::where('status', 'processing')->each(function (Report $report) {
        $batch = Anthropic::batches()->retrieve($report->anthropic_batch_id);

        if ($batch->isEnded()) {
            $report->update(['status' => 'ended']);
            ProcessBatchResultsJob::dispatch($report);
        }
    });
})->everyFiveMinutes();
```

In an Artisan command or a script, `Anthropic::batches()->wait($id)` blocks until the batch ends, checking every 60 seconds. Don't use it in a web request or a job: most batches take minutes to an hour, and up to 24.

## Many calls now, results together

Message Batches cost half, but take minutes to hours. For a handful of calls a user is waiting on, fan out queued jobs with Laravel's own `Bus::batch()` instead:

```php
Bus::batch($articles->map(fn (Article $article) => new SummarizeArticle($article)))
    ->then(fn () => Cache::forget('summaries-pending'))
    ->dispatch();
```

Each job calls `Anthropic::messages()->create()`, and `Anthropic::fake()` answers them in tests. Don't reach for `Concurrency::run()` here: its child processes don't share the fake. As a rule of thumb, send latency-insensitive work of more than about 50 requests to Message Batches, and interactive work to `Bus::batch()`.

Laravel 13's `#[DebounceFor(30)]` on a job that regenerates a summary on every save runs only the last dispatch in the window, so you pay for one call instead of ten. Its other job attributes, such as `#[Timeout(120)]` for a long Claude call, work on these jobs as on any other, and `ShouldBeEncrypted` keeps prompts with personal data out of the queue in plain text.

## Batch processing statuses

| Status | Meaning |
|--------|---------|
| `in_progress` | Batch is being processed |
| `canceling` | Cancellation requested, finishing in-progress requests |
| `ended` | All requests completed (check `requestCounts` for breakdown) |

A canceled batch ends up with status `ended`, not a separate `canceled` status. The `requestCounts` breakdown tells you how many individual requests succeeded, errored, were canceled, or expired. `$batch->isEnded()` is true only for `ended`.

## Reading results yourself

For the scheduled path, `results()` streams them as JSONL, one at a time:

```php
foreach (Anthropic::batches()->results($report->anthropic_batch_id)->succeeded() as $individual) {
    Answer::updateOrCreate(['request_id' => $individual->customId], [
        'content' => collect($individual->result->message->content)->firstWhere('type', 'text')?->text,
    ]);
}
```

`->errored()`, `->canceled()` and `->expired()` loop over the other types, and a plain `foreach` over all of them. They share one stream, so fetch the results again for a second type. An errored result's `$individual->result->error` has the `message` and the `requestId` to quote to support (sometimes null).

## Listing, canceling, deleting

```php
// List with pagination
$response = Anthropic::batches()->list(['limit' => 10]);

// Every batch, one page at a time
foreach (Anthropic::batches()->iterate(['limit' => 100]) as $batch) {
    $batch->processingStatus;
}

// Cancel in-progress
Anthropic::batches()->cancel('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');

// Delete after completion
Anthropic::batches()->delete('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');
```

---

For batch limits, pricing, and the full lifecycle, see the [Batches page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/batches) or the [Batch processing guide](https://platform.claude.com/docs/en/build-with-claude/batch-processing) on the Anthropic docs.
