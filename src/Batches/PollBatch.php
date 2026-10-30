<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Batches;

use Anthropic\Exceptions\ConnectionException;
use Anthropic\Exceptions\ErrorException;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\Connection;
use Anthropic\Laravel\Events\BatchEnded;
use Anthropic\Laravel\Events\BatchResultReceived;
use Anthropic\Laravel\Events\UsageRecorded;
use Anthropic\Laravel\Ledger\AnthropicRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use Throwable;

/**
 * Polls a Message Batch through the connection that created it, releasing itself until the batch ends, each wait
 * twice the last up to 15 minutes; then it dispatches a `BatchResultReceived` event per result (and `UsageRecorded`
 * per succeeded one, with the ledger's rows inserted in chunks) and `BatchEnded`, and runs the `then()` callbacks. One
 * per batch (`ShouldBeUnique`), so a second dispatch can't poll the same batch twice. A key or options it polls with
 * are encrypted in `$sealed`, so nothing that reads its properties sees them.
 */
class PollBatch implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * An exception fails it at once, so a `then()` callback never runs twice.
     */
    public int $maxExceptions = 1;

    /**
     * The seconds the job may run, reading the results included; the worker's `--timeout` without it.
     */
    public ?int $timeout = null;

    /**
     * @param  int  $expiresAt  the Unix time the batch expires at
     * @param  list<SerializableClosure|callable>  $then
     * @param  list<SerializableClosure|callable>  $catch
     * @param  string|null  $sealed  the `withApiKey()` key and the `withOptions()` options, through `Crypt::encrypt()`
     * @param  array{billableType?: ?string, billableId?: int|string|null, feature?: ?string}  $attribution  what
     *                                                                                                       `Anthropic::for()` set when the batch was sent
     */
    public function __construct(
        public readonly string $messageBatchId,
        public readonly string $anthropicConnection,
        public readonly int $every,
        public readonly int $expiresAt,
        protected readonly array $then = [],
        protected readonly array $catch = [],
        protected readonly ?string $sealed = null,
        protected readonly array $attribution = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->messageBatchId;
    }

    /**
     * The unique lock lasts as long as the job may.
     */
    public function uniqueFor(): int
    {
        return max(1, $this->retryUntil() - Date::now()->getTimestamp());
    }

    /**
     * Each release counts as an attempt, so the job is bounded by time instead of tries: past the batch's 24 hours by
     * an hour, or by two of the longest waits, so a last poll still sees it end.
     */
    public function retryUntil(): int
    {
        return $this->expiresAt + max(3600, 2 * $this->longestWait());
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['anthropic', 'anthropic:batch:'.$this->messageBatchId];
    }

    public function handle(AnthropicManager $manager, Dispatcher $events): void
    {
        $client = $this->client($manager);

        try {
            $batch = $client->batches()->retrieve($this->messageBatchId);
            $results = $batch->isEnded() && $batch->resultsUrl !== null ? $client->batches()->results($this->messageBatchId) : null;
        } catch (ConnectionException|ErrorException $e) {
            // The sync queue never runs a released job again, so there the error is final.
            if (! self::transient($e) || $this->job instanceof SyncJob) {
                throw $e;
            }

            $this->release($this->nextWait());

            return;
        }

        if (! $batch->isEnded()) {
            if ($this->job instanceof SyncJob) {
                throw new LogicException("The batch [{$this->messageBatchId}] is {$batch->processingStatus}, and the sync queue can't poll it again: put the poll job on a queue a worker runs (onConnection()), or under Anthropic::fake() queue a BatchResponse that has ended.");
            }

            $this->release($this->nextWait());

            return;
        }

        // With the ledger on, the results' rows go in with a few inserts, not a queued job each.
        $ledger = config('anthropic.ledger.enabled') === true;
        $rows = [];

        foreach ($results ?? [] as $individual) {
            $events->dispatch(new BatchResultReceived($this->anthropicConnection, $this->messageBatchId, $individual->customId, $individual->result->toArray()));

            $message = $individual->result->message;

            if ($message === null) {
                continue;
            }

            $usage = new UsageRecorded(
                connection: $this->anthropicConnection,
                model: $message->model,
                requestId: null,
                usage: $message->usage->toArray(),
                durationMs: null,
                batch: true,
                completed: true,
                billableType: $this->attribution['billableType'] ?? null,
                billableId: $this->attribution['billableId'] ?? null,
                feature: $this->attribution['feature'] ?? null,
                batchId: $this->messageBatchId,
                customId: $individual->customId,
                at: Date::now()->getTimestamp(),
            );
            $events->dispatch($usage);

            if ($ledger) {
                $rows[] = AnthropicRequest::rowFrom($usage);
            }

            // 100 rows keep an insert's bound parameters under SQL Server's 2,100.
            if (count($rows) === 100) {
                $ledger = self::insert($rows);
                $rows = [];
            }
        }

        if ($ledger && $rows !== []) {
            self::insert($rows);
        }

        $events->dispatch(new BatchEnded($this->anthropicConnection, $this->messageBatchId, $batch->toArray()));

        foreach ($this->then as $callback) {
            $callback($batch);
        }
    }

    /**
     * Runs the `catch()` callbacks once polling has failed for good.
     */
    public function failed(Throwable $exception): void
    {
        foreach ($this->catch as $callback) {
            $callback($exception);
        }
    }

    /**
     * The connection to poll through: the one that created the batch, with its key and options.
     */
    protected function client(AnthropicManager $manager): Connection
    {
        /** @var array{api_key?: string|null, options?: list<array<string, mixed>>} $sealed */
        $sealed = $this->sealed === null ? [] : Crypt::decrypt($this->sealed);
        $key = $sealed['api_key'] ?? null;

        $client = is_string($key) ? $manager->withApiKey($key) : $manager->connection($this->anthropicConnection);

        foreach ($sealed['options'] ?? [] as $options) {
            $client = $client->withOptions($options);
        }

        return $client;
    }

    /**
     * The wait before the next poll: twice the last, starting from `every` before the first, up to the longest wait.
     */
    private function nextWait(): int
    {
        $attempts = $this->job?->attempts() ?? 1;

        return (int) min($this->every * 2 ** min(max($attempts, 1), 20), $this->longestWait());
    }

    /**
     * 15 minutes, which keeps a day-long batch near a hundred polls (and attempts: a Laravel 11 `jobs` table counts them
     * in a tinyint), or `every` if that's longer.
     */
    private function longestWait(): int
    {
        return max($this->every, 900);
    }

    /**
     * Writes ledger rows, best effort: a failed insert is reported and ends the ledger's part, never the batch's.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function insert(array $rows): bool
    {
        try {
            AnthropicRequest::query()->insert($rows);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Whether the API or the network may answer the next poll: no answer, a timeout, a conflict, a rate limit, or a
     * server error or overload. Every other error, such as a 404 for a deleted batch, fails the job.
     */
    private static function transient(ConnectionException|ErrorException $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $status = $e->getStatusCode();

        return in_array($status, [408, 409, 429], true) || $status >= 500;
    }
}
