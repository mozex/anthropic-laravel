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
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => 'What is the capital of France?'],
                ],
            ],
        ],
        [
            'custom_id' => 'request-2',
            'params' => [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => 'What is the capital of Germany?'],
                ],
            ],
        ],
    ],
]);

$response->id;                        // 'msgbatch_04Rka1yCsMLGPnR7kfPdgR8x'
$response->processingStatus;          // 'in_progress'
$response->requestCounts->processing; // 2
$response->createdAt;                 // '2026-04-14T12:00:00Z'
$response->expiresAt;                 // '2026-04-15T12:00:00Z'
```

The `params` object inside each request takes the same parameters you'd pass to `Anthropic::messages()->create()`. Tool use, thinking, and system messages all work.

## Polling with a scheduled command

A good Laravel pattern is to store the batch ID on a model, then poll with a scheduled job:

```php
// Save when creating
$job = BatchJob::create([
    'anthropic_batch_id' => $response->id,
    'status' => $response->processingStatus,
]);

// Poll on a schedule (app/Console/Kernel.php)
$schedule->command('batches:poll')->everyFiveMinutes();
```

```php
class PollBatchesCommand extends Command
{
    protected $signature = 'batches:poll';

    public function handle(): void
    {
        BatchJob::whereNotIn('status', ['ended', 'canceled'])
            ->each(function (BatchJob $job) {
                $response = Anthropic::batches()->retrieve($job->anthropic_batch_id);

                $job->update(['status' => $response->processingStatus]);

                if ($response->processingStatus === 'ended') {
                    ProcessBatchResultsJob::dispatch($job);
                }
            });
    }
}
```

## Batch processing statuses

| Status | Meaning |
|--------|---------|
| `in_progress` | Batch is being processed |
| `canceling` | Cancellation requested, finishing in-progress requests |
| `ended` | All requests completed (check `requestCounts` for breakdown) |

A canceled batch ends up with status `ended`, not a separate `canceled` status. The `requestCounts` breakdown tells you how many individual requests succeeded, errored, were canceled, or expired.

## Processing results

Once a batch has ended, stream the results:

```php
class ProcessBatchResultsJob implements ShouldQueue
{
    public function __construct(public BatchJob $job) {}

    public function handle(): void
    {
        $results = Anthropic::batches()->results($this->job->anthropic_batch_id);

        foreach ($results as $individual) {
            if ($individual->result->type === 'succeeded') {
                Answer::create([
                    'request_id' => $individual->customId,
                    'content' => $individual->result->message->content[0]->text,
                ]);
            }

            if ($individual->result->type === 'errored') {
                Log::error('Batch request failed', [
                    'custom_id' => $individual->customId,
                    'error' => $individual->result->error->message,
                ]);
            }
        }
    }
}
```

Results are streamed as JSONL so you can process thousands of items without loading everything into memory.

## Listing, canceling, deleting

```php
// List with pagination
$response = Anthropic::batches()->list(['limit' => 10]);

// Cancel in-progress
Anthropic::batches()->cancel('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');

// Delete after completion
Anthropic::batches()->delete('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');
```

---

For batch limits, pricing, and the full lifecycle, see the [Batches page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/batches) or the [Batch processing guide](https://platform.claude.com/docs/en/build-with-claude/batch-processing) on the Anthropic docs.
