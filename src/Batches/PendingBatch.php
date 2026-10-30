<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Batches;

use Anthropic\Laravel\Connection;
use Anthropic\Laravel\Events\BatchSubmitted;
use Anthropic\Responses\Batches\BatchResponse;
use Anthropic\Testing\ClientFake;
use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * A Message Batch about to be sent: `dispatch()` creates it through its connection, then queues the job that polls it
 * every `every()` seconds until it ends and runs the `then()` callbacks, or the `catch()` ones if polling fails.
 */
class PendingBatch
{
    /**
     * @var list<SerializableClosure|callable>
     */
    private array $then = [];

    /**
     * @var list<SerializableClosure|callable>
     */
    private array $catch = [];

    private ?string $queue = null;

    private ?string $queueConnection = null;

    private int $every = 60;

    private ?int $timeout = null;

    /**
     * @param  list<array<string, mixed>>  $requests
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $requests,
    ) {}

    /**
     * Runs once the batch has ended, whatever its requests' outcomes (each in a `BatchResultReceived` event first),
     * with the ended `BatchResponse`. It runs in the poll job, so a closure can't use `$this`.
     *
     * @param  callable(BatchResponse): mixed  $callback
     */
    public function then(callable $callback): self
    {
        $this->then[] = self::serializable($callback);

        return $this;
    }

    /**
     * Runs when polling fails for good, with what it threw: an error the API won't recover from (a 404 for a deleted
     * batch, a refused key), a `then()` callback that threw, or the job timing out or losing the connection while it
     * reads the results, by when some `BatchResultReceived` events already went out.
     *
     * @param  callable(Throwable): mixed  $callback
     */
    public function catch(callable $callback): self
    {
        $this->catch[] = self::serializable($callback);

        return $this;
    }

    /**
     * The queue the poll job goes on.
     */
    public function onQueue(?string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * The queue connection the poll job goes on (one of `config/queue.php`, not an Anthropic connection).
     */
    public function onConnection(?string $connection): self
    {
        $this->queueConnection = $connection;

        return $this;
    }

    /**
     * The seconds before the first poll (at most 15 minutes, SQS's longest delay), 60 by default; each wait after is
     * twice the last, up to 15 minutes, or this if it's longer.
     */
    public function every(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException("A batch is polled at most every second, every({$seconds}) given.");
        }

        $this->every = $seconds;

        return $this;
    }

    /**
     * The seconds the poll job may run, reading the results included, for a batch whose results take longer than the
     * worker's `--timeout`. Keep the queue connection's `retry_after` (an SQS queue's visibility timeout) above it, or
     * a second worker takes the job while the first still reads.
     */
    public function timeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException("The poll job's timeout is at least a second, timeout({$seconds}) given.");
        }

        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Creates the batch, queues its poll job and dispatches `BatchSubmitted`, and returns the new batch, so its ID can
     * be stored right away. Nothing is sent when the callbacks can't be queued, the queue connection isn't defined, or
     * the poll job would land on a sync queue, which can't poll again.
     */
    public function dispatch(): BatchResponse
    {
        // All of it checked and sealed before the batch is sent, since a batch is billed once it's created.
        serialize([$this->then, $this->catch]);

        if ($this->pollsOnSync()) {
            throw new LogicException("The batch's poll job would run on a sync queue, which can't poll it again, so nothing was sent: put it on a queue a worker runs (onConnection()).");
        }

        $key = $this->connection->apiKey();
        $options = $this->connection->options();
        $sealed = $key === null && $options === [] ? null : Crypt::encrypt(['api_key' => $key, 'options' => $options]);

        $batch = $this->connection->batches()->create(['requests' => $this->requests]);
        $job = $this->job($batch->id, Date::parse($batch->expiresAt)->getTimestamp(), $sealed);

        // The job is queued even when a BatchSubmitted listener throws, so the batch is still polled.
        try {
            app(Events::class)->dispatch(new BatchSubmitted($this->connection->name, $batch->id, $batch->toArray()));
        } finally {
            $this->queue($job, $batch);
        }

        return $batch;
    }

    /**
     * Queues the poll job through a pending dispatch, which takes the unique lock: the bus alone would skip it.
     */
    private function queue(PollBatch $job, BatchResponse $batch): void
    {
        try {
            $pending = dispatch($job);
            unset($pending);
        } catch (Throwable $e) {
            // On the sync queue, which only a fake gets this far on, the job ran inside dispatch(): what it threw is its own.
            if ($this->queueConnection() instanceof SyncQueue) {
                throw $e;
            }

            // Laravel 12 keeps the lock of a job it failed to push, which would stop a second dispatch for a day.
            (new UniqueLock(app(Cache::class)))->release($job);

            throw new RuntimeException("The batch [{$batch->id}] was created, but its poll job couldn't be queued: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * The poll job, the one with the key for a `withApiKey()` batch, on the queue and connection asked.
     */
    private function job(string $id, int $expiresAt, ?string $sealed): PollBatch
    {
        $job = $this->connection->apiKey() === null
            ? new PollBatch($id, $this->connection->name, $this->every, $expiresAt, $this->then, $this->catch, $sealed)
            : new PollBatchWithKey($id, $this->connection->name, $this->every, $expiresAt, $this->then, $this->catch, $sealed);

        $job->timeout = $this->timeout;

        return $job->onQueue($this->queue)->onConnection($this->queueConnection)->delay(min($this->every, 900));
    }

    /**
     * Whether the poll job would run on a real sync queue (`sync`, `deferred` or `background`): a fake client, queue or
     * bus never polls for real. Resolving the connection throws for one that isn't defined, before anything is billed.
     */
    private function pollsOnSync(): bool
    {
        $queue = $this->queueConnection();

        if ($this->connection->client() instanceof ClientFake || app('queue') instanceof Fake || app(Bus::class) instanceof Fake) {
            return false;
        }

        return $queue instanceof SyncQueue;
    }

    /**
     * The queue connection the poll job goes to: `onConnection()`'s, Laravel 13's `Queue::route()`, or the default.
     */
    private function queueConnection(): QueueContract
    {
        // Routes match a job by its class, its parents and interfaces, and its queue.
        $routed = app()->bound('queue.routes') ? app('queue.routes')->getConnection($this->job('', 0, null)) : null;

        // Under Queue::fake(), the fake is every connection.
        return app('queue')->connection($this->queueConnection ?: (is_string($routed) ? $routed : null));
    }

    /**
     * A closure as the queue can store it, as `Bus::batch()` does; any other callable as it is.
     */
    private static function serializable(callable $callback): SerializableClosure|callable
    {
        return $callback instanceof Closure ? new SerializableClosure($callback) : $callback;
    }
}
