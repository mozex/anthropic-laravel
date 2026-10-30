<?php

declare(strict_types=1);

use Anthropic\Exceptions\NotFoundException;
use Anthropic\Exceptions\OverloadedException;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\Batches\PendingBatch;
use Anthropic\Laravel\Batches\PollBatch;
use Anthropic\Laravel\Batches\PollBatchWithKey;
use Anthropic\Laravel\Connection;
use Anthropic\Laravel\Events\BatchEnded;
use Anthropic\Laravel\Events\BatchResultReceived;
use Anthropic\Laravel\Events\BatchSubmitted;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Resources\Batches;
use Anthropic\Responses\Batches\BatchListResponse;
use Anthropic\Responses\Batches\BatchResponse;
use Anthropic\Responses\Batches\BatchResultResponse;
use Anthropic\Testing\Enums\OverrideStrategy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\ExtractProperties;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The two requests of the batches page.
 *
 * @return list<array<string, mixed>>
 */
function batchRequests(): array
{
    return [
        [
            'custom_id' => 'request-1',
            'params' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'What is the capital of France?']]],
        ],
        [
            'custom_id' => 'request-2',
            'params' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'What is the capital of Germany?']]],
        ],
    ];
}

/**
 * The fixture's batch, still processing and expiring a day from now so a worker doesn't drop its poll job.
 */
function submittedBatch(): BatchResponse
{
    // composed: the fixture's batch in progress, expiring tomorrow
    return BatchResponse::fake([
        'processing_status' => 'in_progress',
        'request_counts' => ['processing' => 2, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
        'expires_at' => now()->addDay()->toIso8601ZuluString(),
        'ended_at' => null,
        'results_url' => null,
    ]);
}

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.connections' => ['support' => ['api_key' => 'sk-ant-support']]]);
    Cache::forget('batch');
});

test('dispatch() creates the batch, dispatches BatchSubmitted and queues the poll job for one interval later', function () {
    $this->freezeTime();
    Queue::fake();
    Event::fake([BatchSubmitted::class]);
    Anthropic::fake([submittedBatch()]);

    $batch = Anthropic::batch(batchRequests())->every(300)->onQueue('batches')->onConnection('redis')->dispatch();

    expect($batch->id)->toBe('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');

    Anthropic::assertBatched(fn (array $requests, string $connection): bool => $requests === batchRequests() && $connection === 'default');
    Anthropic::assertBatched(1);
    Event::assertDispatched(BatchSubmitted::class, fn (BatchSubmitted $event): bool => $event->id === $batch->id && $event->connection === 'default' && $event->batch['processing_status'] === 'in_progress');
    Queue::assertPushedOn('batches', PollBatch::class, fn (PollBatch $job): bool => $job->messageBatchId === $batch->id
        && $job->anthropicConnection === 'default'
        && $job->every === 300
        && $job->delay === 300
        && $job->connection === 'redis'
        && $job->tags() === ['anthropic', 'anthropic:batch:msgbatch_04Rka1yCsMLGPnR7kfPdgR8x']
        && $job->retryUntil() === now()->addDay()->addHour()->getTimestamp());
});

test('a queued batch reaches its then callback through the poll job, after an event per result and BatchEnded', function () {
    Event::fake([BatchResultReceived::class, BatchEnded::class]);
    Anthropic::fake([submittedBatch(), BatchResponse::fake(), BatchResultResponse::fake()]);

    Anthropic::batch(batchRequests())
        ->then(static fn (BatchResponse $batch) => Cache::put('batch', [$batch->id, $batch->requestCounts->succeeded]))
        ->dispatch();

    expect(Cache::get('batch'))->toBe(['msgbatch_04Rka1yCsMLGPnR7kfPdgR8x', 95]);

    Anthropic::assertSentInOrder([[Batches::class, 'create'], [Batches::class, 'retrieve'], [Batches::class, 'results']]);
    Anthropic::assertSentOn('default', Batches::class, 3);
    Event::assertDispatchedTimes(BatchResultReceived::class, 2);
    Event::assertDispatched(BatchResultReceived::class, fn (BatchResultReceived $event): bool => $event->customId === 'request-2' && $event->result['type'] === 'errored' && $event->connection === 'default');
    Event::assertDispatched(BatchEnded::class, fn (BatchEnded $event): bool => $event->id === 'msgbatch_04Rka1yCsMLGPnR7kfPdgR8x' && $event->batch['request_counts']['succeeded'] === 95);
});

test('a batch on a named connection is created and polled through it', function () {
    Event::fake([BatchEnded::class]);
    Anthropic::fake([submittedBatch(), BatchResponse::fake(), BatchResultResponse::fake()]);

    Anthropic::connection('support')->batch(batchRequests())
        ->then(static fn (BatchResponse $batch) => Cache::put('batch', $batch->id))
        ->dispatch();

    expect(Cache::get('batch'))->toBe('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');

    Anthropic::assertSentOn('support', Batches::class, 3);
    Anthropic::assertNotSentOn('default', Batches::class);
    Anthropic::assertBatched(fn (array $requests, string $connection): bool => $connection === 'support');
    Event::assertDispatched(BatchEnded::class, fn (BatchEnded $event): bool => $event->connection === 'support');
});

test('the poll job releases itself until the batch ends, and on an error the API may recover from', function (mixed $answer) {
    Anthropic::fake([$answer]);
    $job = (new PollBatch('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x', 'default', 120, now()->addDay()->getTimestamp()))->withFakeQueueInteractions();

    $job->handle(app(AnthropicManager::class), app('events'));

    // the first poll waited `every`, so the next waits twice that
    $job->assertReleased(delay: 240);
})->with([
    'still processing' => [fn () => submittedBatch()],
    'overloaded' => [fn () => OverloadedException::fake()],
]);

test('an error the API won\'t recover from fails the job, and its catch callbacks get it', function () {
    Anthropic::fake([submittedBatch(), NotFoundException::fake()]);
    config(['queue.default' => 'sync']);

    expect(fn () => Anthropic::batch(batchRequests())
        ->then(static fn () => Cache::put('batch', 'then'))
        ->catch(static fn (Throwable $e) => Cache::put('batch', $e::class))
        ->dispatch())->toThrow(NotFoundException::class);

    expect(Cache::get('batch'))->toBe(NotFoundException::class);
});

test('the sync queue, which can\'t poll again, says so instead of stopping', function () {
    Anthropic::fake([submittedBatch(), submittedBatch()]);

    expect(fn () => Anthropic::batch(batchRequests())
        ->catch(static fn (Throwable $e) => Cache::put('batch', $e->getMessage()))
        ->dispatch())->toThrow(LogicException::class, "the sync queue can't poll it again");

    expect(Cache::get('batch'))->toContain('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');
});

test('one poll job per batch', function () {
    Queue::fake();
    // the API answering twice with one batch, as a retried create could
    Anthropic::fake([submittedBatch(), submittedBatch()]);

    Anthropic::batch(batchRequests())->dispatch();
    Anthropic::batch(batchRequests())->dispatch();

    Queue::assertPushed(PollBatch::class, 1);
});

test('a withApiKey() batch is polled with its key, which the queue stores only encrypted', function () {
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
    Anthropic::fake([submittedBatch(), BatchResponse::fake(), BatchResultResponse::fake()]);

    Anthropic::withApiKey('sk-ant-tenant-key')->batch(batchRequests())
        ->then(static fn (BatchResponse $batch) => Cache::put('batch', $batch->id))
        ->dispatch();

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)->not->toContain('sk-ant-tenant-key')
        ->and(json_decode($payload, true)['data']['commandName'])->toBe(PollBatchWithKey::class)
        ->and(serialize(Anthropic::sent()))->not->toContain('sk-ant-tenant-key');

    $this->travel(2)->minutes();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(Cache::get('batch'))->toBe('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x')
        ->and(DB::table('jobs')->count())->toBe(0);

    Anthropic::assertSentOn('default', Batches::class, 3);
});

test('a poll waits twice as long as the last, up to 15 minutes, or every() if that is longer', function (int $every, int $attempts, int $wait) {
    Anthropic::fake([submittedBatch()]);
    $job = (new PollBatch('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x', 'default', $every, now()->addDay()->getTimestamp()))->withFakeQueueInteractions();
    $job->job->attempts = $attempts;

    $job->handle(app(AnthropicManager::class), app('events'));

    $job->assertReleased(delay: $wait);
})->with([
    'after the first poll' => [60, 1, 120],
    'after the third' => [60, 3, 480],
    'after the fourth' => [60, 4, 900],
    'after the hundredth' => [60, 100, 900],
    'an every() past 15 minutes' => [3600, 3, 3600],
]);

test('an error on the results download releases the job too', function () {
    Anthropic::fake([BatchResponse::fake(), OverloadedException::fake()]);
    $job = (new PollBatch('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x', 'default', 60, now()->addDay()->getTimestamp()))->withFakeQueueInteractions();

    $job->handle(app(AnthropicManager::class), app('events'));

    $job->assertReleased(delay: 120);
});

test('on the sync queue, an error the API may recover from is final, since nothing would poll again', function () {
    Anthropic::fake([submittedBatch(), OverloadedException::fake()]);

    expect(fn () => Anthropic::batch(batchRequests())
        ->catch(static fn (Throwable $e) => Cache::put('batch', $e::class))
        ->dispatch())->toThrow(OverloadedException::class);

    expect(Cache::get('batch'))->toBe(OverloadedException::class);
});

test('a real client on the sync queue sends nothing, since nothing would poll the batch', function () {
    config(['anthropic.http.laravel_client' => true, 'queue.default' => 'sync']);
    Http::fake();

    expect(fn () => Anthropic::batch(batchRequests())->dispatch())->toThrow(LogicException::class, 'nothing was sent');

    Http::assertNothingSent();
});

test('callbacks the queue cannot store fail before the batch is sent', function () {
    Queue::fake();
    Anthropic::fake([submittedBatch()]);
    $unserializable = new class {};

    expect(fn () => Anthropic::batch(batchRequests())->then(static fn () => $unserializable)->dispatch())->toThrow(Exception::class, 'is not allowed');

    Anthropic::assertNotBatched();
});

test('the poll job polls with the options and the key the batch was sent with, and nothing that reads its properties sees them', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Queue::fake();
    // composed: a workspace ID of the configuration page's shape, and a gateway header
    $options = ['workspace_id' => 'wrkspc_011CZkZaBF1tNoB5wlCeusgy', 'headers' => ['x-gateway-token' => 'gateway-secret']];
    Anthropic::fake([submittedBatch(), BatchResponse::fake(), BatchResultResponse::fake()]);

    Anthropic::withApiKey('sk-ant-tenant-key')->withOptions($options)->batch(batchRequests())->timeout(600)->dispatch();

    $pushed = Queue::pushed(PollBatchWithKey::class)->first();

    expect($pushed)->toBeInstanceOf(PollBatchWithKey::class)
        ->and($pushed->timeout)->toBe(600)
        ->and(json_encode(ExtractProperties::from($pushed)))->not->toContain('sk-ant-tenant-key')->not->toContain('gateway-secret')
        ->and(serialize($pushed))->not->toContain('sk-ant-tenant-key')->not->toContain('gateway-secret');

    $pushed->withFakeQueueInteractions()->handle(app(AnthropicManager::class), app('events'));

    expect(array_map(fn ($request): array => $request->options(), Anthropic::sent(Batches::class)))
        ->each->toMatchArray(['workspace_id' => 'wrkspc_011CZkZaBF1tNoB5wlCeusgy']);
    Anthropic::assertSentTimes(Batches::class, 3);
});

test('every() and timeout() take whole seconds of at least one', function () {
    expect(fn () => Anthropic::batch(batchRequests())->every(0))->toThrow(InvalidArgumentException::class, 'every(0) given')
        ->and(fn () => Anthropic::batch(batchRequests())->timeout(0))->toThrow(InvalidArgumentException::class, 'timeout(0) given');
});

test('the first poll waits at most 15 minutes, SQS\'s longest delay, and the lock lasts as long as the job', function () {
    $this->freezeTime();
    Queue::fake();
    Anthropic::fake([submittedBatch()]);

    Anthropic::batch(batchRequests())->every(3600)->dispatch();

    Queue::assertPushed(PollBatch::class, fn (PollBatch $job): bool => $job->delay === 900
        // the deadline grows with the longest wait: two of an hour each
        && $job->retryUntil() === now()->addDay()->addHours(2)->getTimestamp()
        && $job->uniqueFor() === $job->retryUntil() - now()->getTimestamp());
});

test('a deferred or background queue is a sync one too: a real client sends nothing on it', function (string $driver) {
    config(['anthropic.http.laravel_client' => true, 'queue.connections.later' => ['driver' => $driver], 'queue.default' => 'later']);
    Http::fake();

    expect(fn () => Anthropic::batch(batchRequests())->dispatch())->toThrow(LogicException::class, 'nothing was sent');

    Http::assertNothingSent();
})->with(['deferred', 'background']);

test('a queue connection that isn\'t defined throws before the batch is sent', function () {
    Anthropic::fake([submittedBatch()]);

    expect(fn () => Anthropic::batch(batchRequests())->onConnection('reddis')->dispatch())->toThrow(InvalidArgumentException::class, '[reddis]');

    Anthropic::assertNotBatched();
});

test('a BatchSubmitted listener that throws still leaves the batch polled', function () {
    Queue::fake();
    Anthropic::fake([submittedBatch()]);
    Event::listen(BatchSubmitted::class, fn () => throw new RuntimeException('listener failed'));

    expect(fn () => Anthropic::batch(batchRequests())->dispatch())->toThrow(RuntimeException::class, 'listener failed');

    Queue::assertPushed(PollBatch::class, 1);
});

test('a withApiKey() batch is polled with its own key, on a real client', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'anthropic.http.laravel_client' => true]);
    Http::fake(['*' => Http::response(submittedBatch()->toArray())]);
    $sealed = Crypt::encrypt(['api_key' => 'sk-ant-tenant-key', 'options' => []]);

    (new PollBatchWithKey('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x', 'default', 60, now()->addDay()->getTimestamp(), sealed: $sealed))
        ->withFakeQueueInteractions()
        ->handle(app(AnthropicManager::class), app('events'));

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'sk-ant-tenant-key'));
    Http::assertNotSent(fn ($request): bool => $request->hasHeader('x-api-key', 'sk-ant-test'));
});

test('assertNotBatched() passes until a batch the callback accepts is created', function () {
    Queue::fake();
    Anthropic::fake([submittedBatch()]);

    Anthropic::assertNotBatched();

    Anthropic::connection('support')->batch(batchRequests())->dispatch();

    Anthropic::assertNotBatched(fn (array $requests, string $connection): bool => $connection === 'default');
    expect(fn () => Anthropic::assertNotBatched())->toThrow(ExpectationFailedException::class, 'An unexpected batch was created.');
});

test('a connection keeps its name, its key and batch() through withOptions(), and hands the PHP client what it alone has', function () {
    $tenant = Anthropic::withApiKey('sk-ant-tenant-key')->withOptions(['betas' => ['compact-2026-09-04']]);

    expect($tenant)->toBeInstanceOf(Connection::class)
        ->and($tenant->name)->toBe('default')
        ->and($tenant->apiKey())->toBe('sk-ant-tenant-key')
        ->and($tenant->batch(batchRequests()))->toBeInstanceOf(PendingBatch::class)
        ->and(Anthropic::connection('support')->settings()->betas)->toBe([]);
});

test('anthropic:batches lists the latest batches, or shows one, on the connection asked', function () {
    Anthropic::fake([BatchListResponse::fake(), BatchResponse::fake()]);

    $this->artisan('anthropic:batches', ['--connection' => 'support', '--limit' => 5])
        ->expectsOutputToContain('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x')
        ->assertSuccessful();

    $this->artisan('anthropic:batches', ['id' => 'msgbatch_04Rka1yCsMLGPnR7kfPdgR8x'])
        ->expectsOutputToContain('Succeeded')
        ->expectsOutputToContain('2025-04-01T12:30:00Z')
        ->assertSuccessful();

    Anthropic::assertSentOn('support', Batches::class, fn (string $method, array $parameters = []): bool => $method === 'list' && $parameters === ['limit' => 5]);
    Anthropic::assertSentOn('default', Batches::class, fn (string $method, string $id = ''): bool => $method === 'retrieve' && $id === 'msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');
});

test('anthropic:batches says when there are none, and refuses a limit that is not a number', function () {
    Anthropic::fake([BatchListResponse::fake(['data' => [], 'has_more' => false, 'first_id' => null, 'last_id' => null], strategy: OverrideStrategy::Replace)]);

    $this->artisan('anthropic:batches')->expectsOutputToContain('No batches yet.')->assertSuccessful();
    $this->artisan('anthropic:batches', ['--limit' => 'ten'])->expectsOutputToContain('--limit takes a whole number')->assertFailed();

    Anthropic::assertSentTimes(Batches::class, 1);
});

test('anthropic:batches fails with the API\'s message', function () {
    Anthropic::fake([NotFoundException::fake()]);

    $this->artisan('anthropic:batches', ['id' => 'msgbatch_missing'])
        ->expectsOutputToContain('The requested resource was not found.')
        ->assertFailed();
});

test('a poll job that can\'t be queued names the batch that was created', function () {
    config(['queue.connections.broken' => ['driver' => 'database', 'table' => 'no_such_table', 'queue' => 'default', 'retry_after' => 90]]);
    Anthropic::fake([submittedBatch()]);

    expect(fn () => Anthropic::batch(batchRequests())->onConnection('broken')->dispatch())
        ->toThrow(RuntimeException::class, 'The batch [msgbatch_04Rka1yCsMLGPnR7kfPdgR8x] was created, but its poll job couldn\'t be queued');

    Anthropic::assertBatched(1);
});

test('a queue route off the sync queue lets a real client send the batch', function () {
    config(['anthropic.http.laravel_client' => true, 'queue.default' => 'sync', 'queue.connections.routed' => ['driver' => 'null']]);
    Http::fake(['*' => Http::response(submittedBatch()->toArray())]);
    Queue::route(PollBatch::class, connection: 'routed');

    expect(Anthropic::batch(batchRequests())->dispatch()->id)->toBe('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x');

    Http::assertSentCount(1);
})->skip(fn (): bool => ! app()->bound('queue.routes'), 'Queue::route() is Laravel 13');
