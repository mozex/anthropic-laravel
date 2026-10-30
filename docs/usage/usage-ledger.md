---
title: Usage Ledger
weight: 12
---

The usage ledger writes every billed Messages call to a table of your own, with its tokens and who you billed it to, so you can see what each user, team or feature spends. It never stores a price: multiply by your own rates.

## Turning it on

Publish the migration, run it, and switch the ledger on:

```bash
php artisan vendor:publish --tag=anthropic-migrations
php artisan migrate
```

```dotenv
ANTHROPIC_LEDGER=true
```

Billing models with UUID or ULID keys? Change the migration's `nullableMorphs('billable')` to `nullableUuidMorphs` or `nullableUlidMorphs` before you run it.

Every non-streamed Messages answer, every stream once it ends (or is dropped, with the tokens it had), and every result of an [`Anthropic::batch()`](./batches.md) then writes a row to `anthropic_requests`: the model, the connection, the input, output, cache-read and cache-write tokens, the server tools' counts, the whole `usage` as JSON (with the cache's 5-minute and 1-hour split, for pricing), the service tier, the speed, whether it was a batch or completed, the request ID and the duration. A batch row also has its batch ID and `custom_id`. A call with `usage.iterations` (compaction, the advisor, a server-side fallback) counts its tokens across them, since the top-level counts leave those out.

Rows are written by a queued listener (`ANTHROPIC_LEDGER_QUEUE` picks the queue), and a batch's poll job writes its results itself, in chunks. Writing is best effort: a row that fails is reported, and never fails the call or the batch. Token counts and errors write nothing, and neither does a stream you never read. With the ledger off, nothing touches the database. `duration_ms` covers the whole attempt for a non-streamed call, and from the headers to the end for a stream.

Batch results you read by hand through `Anthropic::batches()->results()` aren't recorded; send the batch with `Anthropic::batch()` for that.

## Billing calls to someone

`Anthropic::for()` names who the calls after it are billed to, and what they're for:

```php
use Anthropic\Laravel\Facades\Anthropic;

Anthropic::for($request->user(), feature: 'summaries')->messages()->create([...]);
```

It holds for the rest of the request or job, and for the jobs it dispatches, so a job queued from a controller bills the same user. In a loop over users, call it for each one: a call without it is billed to whoever was named last. It lives in hidden Context, out of your log lines, and it's read when a call's usage is recorded (a stream's when it ends), never from `auth()`. A batch keeps the billable it was sent with, even though its results arrive later. `Anthropic::for(null)` clears it.

The `billable` columns are a morph, so `$row->billable` is the model. A relation on your model sums what it spent:

```php
public function anthropicRequests(): MorphMany
{
    return $this->morphMany(\Anthropic\Laravel\Ledger\AnthropicRequest::class, 'billable');
}

$team->anthropicRequests()->where('created_at', '>=', now()->startOfMonth())->sum('output_tokens');
```

## Summing it up

```bash
php artisan anthropic:usage                       # the last 30 days, by model
php artisan anthropic:usage --by=feature --days=7
php artisan anthropic:usage --by=billable
php artisan anthropic:usage --by=day
php artisan anthropic:usage --by=connection
```

Each row has the requests and the input, output and cache tokens.

## Pruning old rows

Rows older than `prune_after_days` (`ANTHROPIC_LEDGER_PRUNE_DAYS`, 90 by default; 0 keeps them all) go with Laravel's `model:prune`. Name the model: without `--model`, `model:prune` only looks in `app/Models`.

```php
// routes/console.php
Schedule::command('model:prune', ['--model' => [\Anthropic\Laravel\Ledger\AnthropicRequest::class]])->daily();
```

## Listening yourself

Each recorded call dispatches `Anthropic\Laravel\Events\UsageRecorded`, with the ledger on or off: the connection, the model, the request ID, the `usage` array as the API sent it, the duration, `batch` (with the batch ID and `custom_id`), `completed`, the billable and feature `Anthropic::for()` set, the time it was billed, and the [token budgets](./token-budgets.md) it was charged to. A listener of your own can send it to a metrics service:

```php
Event::listen(function (UsageRecorded $usage) {
    Metrics::increment('claude.output_tokens', $usage->usage['output_tokens'] ?? 0, ['feature' => $usage->feature]);
});
```

A call or a stream under `Anthropic::fake()` dispatches none, but an `Anthropic::batch()` poll job still dispatches one per succeeded result (and writes its rows), as it does `BatchResultReceived`.

---

For a stream's usage outside Laravel, see [Knowing when a stream ends](https://mozex.dev/docs/anthropic-php/v1/usage/streaming#knowing-when-a-stream-ends) in the PHP docs, and for what each token count means, Anthropic's [pricing page](https://platform.claude.com/docs/en/about-claude/pricing).
