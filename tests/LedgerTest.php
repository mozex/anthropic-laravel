<?php

declare(strict_types=1);

use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\AnthropicServiceProvider;
use Anthropic\Laravel\Batches\PollBatch;
use Anthropic\Laravel\Events\UsageRecorded;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Ledger\AnthropicRequest;
use Anthropic\Laravel\ServiceProvider;
use Anthropic\Responses\Batches\BatchResponse;
use Anthropic\Responses\Batches\BatchResultResponse;
use Anthropic\Responses\Messages\CreateResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

class LedgerUser extends Model
{
    protected $table = 'users';
}

/**
 * The ledger as an app turns it on: the setting, the migration run, the provider booted with it.
 */
function enableLedger(): void
{
    config(['anthropic.ledger.enabled' => true]);
    (require __DIR__.'/../database/migrations/2026_10_30_000000_create_anthropic_requests_table.php')->up();
    (new AnthropicServiceProvider(app()))->boot();
}

/**
 * A user to bill, without a users table.
 */
function ledgerUser(int $id = 7): LedgerUser
{
    $user = new LedgerUser;
    $user->id = $id;

    return $user;
}

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
});

test('a non-streamed call writes one row, billed to who Anthropic::for() named', function () {
    enableLedger();
    Http::fake(['*' => Http::response(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_018EeWyXxfu5pfWkrYcMdjWG'])]);

    Anthropic::for(ledgerUser(), feature: 'summaries')->messages()->create(['model' => 'claude-sonnet-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello!']]]);

    $row = AnthropicRequest::query()->sole();

    expect($row->model)->toBe(CreateResponse::fake()->model)
        ->and($row->request_id)->toBe('req_018EeWyXxfu5pfWkrYcMdjWG')
        ->and($row->input_tokens)->toBe(CreateResponse::fake()->usage->inputTokens)
        ->and($row->output_tokens)->toBe(CreateResponse::fake()->usage->outputTokens)
        ->and($row->billable_type)->toBe(LedgerUser::class)
        ->and((int) $row->billable_id)->toBe(7)
        ->and($row->feature)->toBe('summaries')
        ->and($row->batch)->toBeFalse()
        ->and($row->completed)->toBeTrue();
});

test('a stream writes its row once it ends, and one dropped early writes what it had', function () {
    enableLedger();
    $stream = file_get_contents(__DIR__.'/../vendor/mozex/anthropic-php/src/Testing/Responses/Fixtures/Messages/CreateStreamedResponseFixture.txt');
    // a new response each time: one body is read once
    Http::fake(['*' => fn () => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);
    $parameters = ['model' => 'claude-sonnet-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello!']]];

    iterator_to_array(Anthropic::messages()->createStreamed($parameters));

    $dropped = Anthropic::messages()->createStreamed($parameters);

    foreach ($dropped as $event) {
        break;
    }

    unset($dropped, $event);

    // the fixture's message_start counts 14 in and 1 out, its message_delta 15 out
    expect(AnthropicRequest::query()->orderBy('id')->get(['completed', 'input_tokens', 'output_tokens'])->toArray())->toBe([
        ['completed' => true, 'input_tokens' => 14, 'output_tokens' => 15],
        ['completed' => false, 'input_tokens' => 14, 'output_tokens' => 1],
    ]);
});

test('a token count and an error write nothing', function () {
    enableLedger();
    Http::fake([
        'api.anthropic.com/v1/messages/count_tokens' => Http::response(['input_tokens' => 14]),
        '*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
    ]);

    Anthropic::messages()->countTokens(['model' => 'claude-sonnet-5-5', 'messages' => [['role' => 'user', 'content' => 'Hello!']]]);
    rescue(fn () => Anthropic::messages()->create(['model' => 'claude-sonnet-5-5']), report: false);

    expect(AnthropicRequest::query()->count())->toBe(0);
});

test('a batch\'s results are written by its poll job in a few inserts, billed to who was named when it was sent', function () {
    enableLedger();
    Queue::fake();
    Anthropic::fake([BatchResponse::fake(['processing_status' => 'in_progress']), BatchResponse::fake(), BatchResultResponse::fake()]);

    Anthropic::for(ledgerUser(9), feature: 'nightly')->batch([['custom_id' => 'request-1', 'params' => []]])->dispatch();
    // the job runs later, after this request's billable is gone
    Anthropic::for(null);
    $inserts = 0;
    DB::listen(function ($query) use (&$inserts) {
        $inserts += str_starts_with(strtolower($query->sql), 'insert') ? 1 : 0;
    });

    Queue::pushed(PollBatch::class)->first()->withFakeQueueInteractions()->handle(app(AnthropicManager::class), app('events'));

    // the fixture's results: one succeeded, one errored
    $row = AnthropicRequest::query()->sole();

    expect($row->batch)->toBeTrue()
        ->and($row->billable_type)->toBe(LedgerUser::class)
        ->and((int) $row->billable_id)->toBe(9)
        ->and($row->feature)->toBe('nightly')
        ->and($row->batch_id)->toBe('msgbatch_04Rka1yCsMLGPnR7kfPdgR8x')
        ->and($row->custom_id)->toBe('request-1')
        ->and($inserts)->toBe(1);

    // the poll job wrote them: no queued ledger job per result
    Queue::assertNotPushed(CallQueuedListener::class);
});

test('a call billed with usage.iterations counts across them, and the row keeps the whole usage', function () {
    enableLedger();
    // composed: a compaction iteration and the answer's, in the shape of the compaction docs' usage.iterations
    $usage = ['input_tokens' => 23000, 'output_tokens' => 1000, 'iterations' => [['type' => 'compaction', 'input_tokens' => 180000, 'output_tokens' => 3500], ['type' => 'message', 'input_tokens' => 23000, 'output_tokens' => 1000]]];
    Http::fake(['*' => Http::response(CreateResponse::fake(['usage' => $usage])->toArray())]);

    Anthropic::messages()->create(['model' => 'claude-sonnet-5-5']);

    $row = AnthropicRequest::query()->sole();

    expect($row->input_tokens)->toBe(203000)
        ->and($row->output_tokens)->toBe(4500)
        ->and($row->usage['iterations'])->toHaveCount(2);
});

test('a stream that fails mid-way writes what it had, not completed', function () {
    enableLedger();
    // composed: the fixture's message_start, then the streaming page's overloaded error
    $sse = "event: message_start\n"
        .'data: {"type":"message_start","message":{"id":"msg_1nZdL29xx5MUA1yADyHTEsnR8uuvGzszyY","type":"message","role":"assistant","content":[],"model":"claude-sonnet-4-6","stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":25,"output_tokens":1}}}'."\n\n"
        ."event: error\n"
        .'data: {"type": "error", "error": {"type": "overloaded_error", "message": "Overloaded"}}'."\n\n";
    Http::fake(['*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    rescue(fn () => iterator_to_array(Anthropic::messages()->createStreamed(['model' => 'claude-sonnet-4-6'])), report: false);

    expect(AnthropicRequest::query()->sole()->only(['completed', 'input_tokens']))->toBe(['completed' => false, 'input_tokens' => 25]);
});

test('a UsageRecorded listener that throws is reported, never thrown into the reader of a stream', function () {
    Exceptions::fake();
    Event::listen(UsageRecorded::class, fn () => throw new RuntimeException('metrics are down'));
    $stream = file_get_contents(__DIR__.'/../vendor/mozex/anthropic-php/src/Testing/Responses/Fixtures/Messages/CreateStreamedResponseFixture.txt');
    Http::fake(['*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);

    iterator_to_array(Anthropic::messages()->createStreamed(['model' => 'claude-sonnet-4-6']));

    Exceptions::assertReported(RuntimeException::class);
});

test('the billable follows a job dispatched after Anthropic::for()', function () {
    enableLedger();
    Http::fake(['*' => Http::response(CreateResponse::fake()->toArray())]);

    Anthropic::for(ledgerUser(5), feature: 'reports');
    dispatch(function () {
        Anthropic::messages()->create(['model' => 'claude-sonnet-5-5']);
    });

    $row = AnthropicRequest::query()->sole();

    expect($row->feature)->toBe('reports')
        ->and((int) $row->billable_id)->toBe(5);
});

test('UsageRecorded carries the call and its billing whether or not the ledger is on', function () {
    Event::fake([UsageRecorded::class]);
    Http::fake(['*' => Http::response(CreateResponse::fake()->toArray())]);

    Anthropic::for(ledgerUser(), feature: 'chat')->messages()->create(['model' => 'claude-sonnet-5-5']);

    Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $usage): bool => $usage->connection === 'default'
        && $usage->usage['input_tokens'] === CreateResponse::fake()->usage->inputTokens
        && $usage->billableId === 7
        && $usage->feature === 'chat'
        && ! $usage->batch);
});

test('without the ledger, nothing touches the database', function () {
    Http::fake(['*' => Http::response(CreateResponse::fake()->toArray())]);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Anthropic::messages()->create(['model' => 'claude-sonnet-5-5']);

    expect($queries)->toBe(0)
        ->and(Schema::hasTable('anthropic_requests'))->toBeFalse();
});

test('anthropic:usage sums the ledger by model, feature or day, and says when it\'s off', function () {
    $this->artisan('anthropic:usage')->expectsOutputToContain('has no `ledger` array')->assertFailed();

    config(['anthropic.ledger' => ['enabled' => false]]);
    $this->artisan('anthropic:usage')->expectsOutputToContain('The usage ledger is off')->assertFailed();

    enableLedger();
    AnthropicRequest::query()->insert([
        ['connection' => 'default', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 1000, 'output_tokens' => 200, 'feature' => 'chat', 'batch' => false, 'completed' => true, 'created_at' => now()],
        ['connection' => 'default', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 500, 'output_tokens' => 100, 'feature' => 'summaries', 'batch' => false, 'completed' => true, 'created_at' => now()],
        ['connection' => 'default', 'model' => 'claude-haiku-5-5', 'input_tokens' => 50, 'output_tokens' => 10, 'feature' => 'chat', 'batch' => false, 'completed' => true, 'created_at' => now()->subDays(40)],
    ]);

    expect(Artisan::call('anthropic:usage'))->toBe(0)
        ->and(Artisan::output())->toMatch('/claude-sonnet-5-5\s*\|\s*2\s*\|\s*1,500\s*\|\s*300/')->not->toContain('claude-haiku-5-5')
        ->and(Artisan::call('anthropic:usage', ['--by' => 'feature', '--days' => 60]))->toBe(0)
        ->and(Artisan::output())->toMatch('/chat\s*\|\s*2\s*\|\s*1,050/')
        ->and(Artisan::call('anthropic:usage', ['--by' => 'day']))->toBe(0)
        ->and(Artisan::output())->toContain(now()->toDateString());
    $this->artisan('anthropic:usage', ['--by' => 'price'])->expectsOutputToContain('--by takes')->assertFailed();

    AnthropicRequest::query()->update(['billable_type' => LedgerUser::class, 'billable_id' => 3]);

    expect(Artisan::call('anthropic:usage', ['--by' => 'billable', '--days' => 60]))->toBe(0)
        ->and(Artisan::output())->toContain(LedgerUser::class.':3')
        ->and(Artisan::call('anthropic:usage', ['--by' => 'connection']))->toBe(0)
        ->and(Artisan::output())->toContain('default');
});

test('rows older than prune_after_days go with model:prune --model, and 0 keeps them all', function () {
    enableLedger();
    config(['anthropic.ledger.prune_after_days' => 0]);
    AnthropicRequest::query()->insert(['connection' => 'default', 'model' => 'claude-sonnet-5-5', 'batch' => false, 'completed' => true, 'created_at' => now()->subYears(2)]);

    // 0 keeps them all
    $this->artisan('model:prune', ['--model' => AnthropicRequest::class])->assertSuccessful();
    expect(AnthropicRequest::query()->count())->toBe(1);

    AnthropicRequest::query()->delete();
    config(['anthropic.ledger.prune_after_days' => 30]);
    AnthropicRequest::query()->insert([
        ['connection' => 'default', 'model' => 'claude-sonnet-5-5', 'batch' => false, 'completed' => true, 'created_at' => now()->subDays(31)],
        ['connection' => 'default', 'model' => 'claude-sonnet-5-5', 'batch' => false, 'completed' => true, 'created_at' => now()->subDays(29)],
    ]);

    $this->artisan('model:prune', ['--model' => AnthropicRequest::class])->assertSuccessful();

    expect(AnthropicRequest::query()->count())->toBe(1);
});

test('the migration is published under its tag', function () {
    expect(BaseServiceProvider::pathsToPublish(ServiceProvider::class, 'anthropic-migrations'))->toHaveCount(1);
});
