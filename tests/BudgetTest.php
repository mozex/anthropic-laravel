<?php

declare(strict_types=1);

use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\Batches\PollBatch;
use Anthropic\Laravel\Events\ResponseReceived;
use Anthropic\Laravel\Exceptions\BudgetExceeded;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Queue\TokenBudget;
use Anthropic\Responses\Batches\BatchResponse;
use Anthropic\Responses\Batches\BatchResultResponse;
use Anthropic\Responses\Messages\CreateResponse;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A request the budget tests send.
 *
 * @return array<string, mixed>
 */
function budgetRequest(): array
{
    return ['model' => 'claude-sonnet-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello!']]];
}

/**
 * An answer with cache reads, so billed and rate-limit counts differ.
 *
 * @return array<string, mixed>
 */
function budgetAnswer(): array
{
    // composed: the fixture's answer with a cache read
    return CreateResponse::fake(['usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 100]])->toArray();
}

/**
 * A job held to a budget, with no queue job behind it: run directly.
 */
function budgetJob(): object
{
    return new class implements ShouldQueue
    {
        use InteractsWithQueue;
        use Queueable;
    };
}

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
    Anthropic::budget('user:7')->perDay(1000)->clear();
});

test('a budget counts what it is charged, and says when it is used up', function () {
    $budget = Anthropic::budget('user:7')->perDay(1000);

    expect($budget->remaining())->toBe(1000)
        ->and($budget->charge(['input_tokens' => 400, 'output_tokens' => 100, 'cache_read_input_tokens' => 100]))->toBe(600)
        ->and($budget->remaining())->toBe(400)
        ->and($budget->exceeded())->toBeFalse();

    $budget->charge(['input_tokens' => 400, 'output_tokens' => 100]);

    expect($budget->exceeded())->toBeTrue()
        ->and($budget->remaining())->toBe(0)
        ->and($budget->availableIn())->toBeGreaterThan(86000)
        ->and(fn () => $budget->ensure())->toThrow(BudgetExceeded::class, 'The token budget of 1000 tokens is used up');
});

test('rate counting leaves cache reads out', function () {
    expect(Anthropic::budget('user:7')->perDay(1000)->counting('rate')->charge(['input_tokens' => 10, 'output_tokens' => 20, 'cache_read_input_tokens' => 100]))->toBe(30)
        ->and(fn () => Anthropic::budget('user:7')->counting('price'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Anthropic::budget('user:7')->perDay(0))->toThrow(InvalidArgumentException::class);
});

test('an enforced budget charges each call as it is billed, and refuses the next before it is sent once used up', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);

    Anthropic::budget('user:7')->perDay(150)->enforce();

    Anthropic::messages()->create(budgetRequest());

    expect(Anthropic::budget('user:7')->perDay(150)->remaining())->toBe(20);

    Anthropic::messages()->create(budgetRequest());

    expect(fn () => Anthropic::messages()->create(budgetRequest()))->toThrow(BudgetExceeded::class);

    Http::assertSentCount(2);
});

test('a token count goes through a used-up budget, and charges nothing', function () {
    Http::fake(['*' => Http::response(['input_tokens' => 14])]);
    $budget = Anthropic::budget('user:7')->perDay(10)->enforce();
    $budget->charge(['input_tokens' => 10, 'output_tokens' => 0]);

    Anthropic::messages()->countTokens(budgetRequest());

    Http::assertSentCount(1);
    expect($budget->remaining())->toBe(0);
});

test('a stream charges its tokens once it ends', function () {
    $stream = file_get_contents(__DIR__.'/../vendor/mozex/anthropic-php/src/Testing/Responses/Fixtures/Messages/CreateStreamedResponseFixture.txt');
    Http::fake(['*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);
    Anthropic::budget('user:7')->perDay(1000)->enforce();

    iterator_to_array(Anthropic::messages()->createStreamed(budgetRequest()));

    // the fixture's 14 in and 15 out
    expect(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(971);
});

test('a batch charges its results to the budget it was sent under, when its poll job reads them', function () {
    Queue::fake();
    Anthropic::fake([BatchResponse::fake(['processing_status' => 'in_progress']), BatchResponse::fake(), BatchResultResponse::fake()]);
    Anthropic::budget('team:3')->perDay(100000)->clear();
    Anthropic::budget('team:3')->perDay(100000)->enforce();

    Anthropic::batch([['custom_id' => 'request-1', 'params' => []]])->dispatch();

    Queue::pushed(PollBatch::class)->first()->withFakeQueueInteractions()->handle(app(AnthropicManager::class), app('events'));

    $succeeded = iterator_to_array(BatchResultResponse::fake()->succeeded(), false)[0]->result->message->usage;

    expect(Anthropic::budget('team:3')->perDay(100000)->remaining())->toBe(100000 - $succeeded->billedTokens());
});

test('the route middleware answers 429 with Retry-After for a used-up budget, and enforces one with room', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Route::middleware('anthropic.budget:user,150,day')->get('/ask', fn () => Anthropic::messages()->create(budgetRequest())->id);

    // signed out, so the budget is the IP's
    Anthropic::budget('user:127.0.0.1')->perDay(150)->clear();

    $this->get('/ask')->assertOk();

    expect(Anthropic::budget('user:127.0.0.1')->perDay(150)->remaining())->toBe(20);

    $this->get('/ask')->assertOk();
    $this->get('/ask')->assertStatus(429)->assertHeader('Retry-After');

    Http::assertSentCount(2);
});

test('the queue middleware releases a job until the budget resets, and runs it within one', function () {
    $job = budgetJob();
    $job->withFakeQueueInteractions();
    $budget = Anthropic::budget('team:3')->perDay(100);
    $budget->clear();
    $ran = false;

    (new TokenBudget($budget))->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();

    $budget->charge(['input_tokens' => 100, 'output_tokens' => 0]);
    $ran = false;

    (new TokenBudget($budget))->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse();
    $job->assertReleased();

    // run directly, it can't be released for later
    expect(fn () => (new TokenBudget($budget))->handle(budgetJob(), fn () => null))->toThrow(BudgetExceeded::class);
});

test('budgets on one key in two windows both hold the calls, and a refusal waits for the one that resets last', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Anthropic::budget('user:7')->perMinute(150)->clear();

    Anthropic::budget('user:7')->perMinute(150)->enforce();
    Anthropic::budget('user:7')->perDay(1000)->enforce();

    Anthropic::messages()->create(budgetRequest());

    expect(Anthropic::budget('user:7')->perMinute(150)->remaining())->toBe(20)
        ->and(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(870);

    Anthropic::budget('user:7')->perDay(1000)->charge(['input_tokens' => 870, 'output_tokens' => 0]);
    Anthropic::budget('user:7')->perMinute(150)->charge(['input_tokens' => 20, 'output_tokens' => 0]);

    try {
        Anthropic::messages()->create(budgetRequest());
        $this->fail('The call went through a used-up budget.');
    } catch (BudgetExceeded $e) {
        expect($e->key)->toBe('user:7')->and($e->availableIn)->toBeGreaterThan(60);
    }

    Http::assertSentCount(1);
});

test('a budget enforced with rate counting keeps counting that way', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Anthropic::budget('user:7')->perDay(1000)->counting('rate')->enforce();

    Anthropic::messages()->create(budgetRequest());

    // 10 in and 20 out, without the 100 cache reads
    expect(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(970);
});

test('a batch is refused before it is sent under a used-up budget', function () {
    Http::fake();
    $budget = Anthropic::budget('user:7')->perDay(10)->enforce();
    $budget->charge(['input_tokens' => 10, 'output_tokens' => 0]);

    expect(fn () => Anthropic::batches()->create(['requests' => [['custom_id' => 'request-1', 'params' => budgetRequest()]]]))->toThrow(BudgetExceeded::class);

    Http::assertNothingSent();
});

test('a budget with no size is a mistake, not an empty budget', function () {
    expect(fn () => Anthropic::budget('user:7')->enforce())->toThrow(LogicException::class)
        ->and(fn () => Anthropic::budget('user:7')->exceeded())->toThrow(LogicException::class);
});

test('the route middleware keys a signed-in user by their ID', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Route::middleware('anthropic.budget:user,1000,day')->get('/ask', fn () => Anthropic::messages()->create(budgetRequest())->id);
    Anthropic::budget('user:42')->perDay(1000)->clear();

    $this->actingAs(new GenericUser(['id' => 42]))->get('/ask')->assertOk();

    expect(Anthropic::budget('user:42')->perDay(1000)->remaining())->toBe(870);
});

test('a budget used up partway through a request answers 429 with Retry-After', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Route::middleware('anthropic.budget:user,100,day')->get('/ask-twice', function () {
        Anthropic::messages()->create(budgetRequest());

        return Anthropic::messages()->create(budgetRequest())->id;
    });
    Anthropic::budget('user:127.0.0.1')->perDay(100)->clear();

    $this->getJson('/ask-twice')->assertStatus(429)->assertHeader('Retry-After');

    Http::assertSentCount(1);
});

test('the route middleware takes its tokens as a whole number and a known window', function () {
    $this->withoutExceptionHandling();
    Route::middleware('anthropic.budget:user,200_000,day')->get('/underscores', fn () => 'ok');
    Route::middleware('anthropic.budget:user,1000,week')->get('/week', fn () => 'ok');

    expect(fn () => $this->get('/underscores'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->get('/week'))->toThrow(InvalidArgumentException::class);
});

test('a job held to a budget gives the caller its own budgets back, as on the sync queue', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    $team = Anthropic::budget('team:3')->perDay(1000);
    $team->clear();
    Anthropic::budget('user:7')->perDay(1000)->enforce();

    (new TokenBudget($team))->handle(new class {}, fn () => Anthropic::messages()->create(budgetRequest()));

    // the job's call went to both: the budget it inherited and its own
    expect(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(870)
        ->and($team->remaining())->toBe(870);

    Anthropic::messages()->create(budgetRequest());

    expect(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(740)
        ->and($team->remaining())->toBe(870);
});

test('a queued job that runs out partway is released, for no longer than SQS holds a job', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    $job = budgetJob();
    $job->withFakeQueueInteractions();
    $budget = Anthropic::budget('team:3')->perDay(100);
    $budget->clear();

    (new TokenBudget($budget))->handle($job, function () {
        Anthropic::messages()->create(budgetRequest());
        Anthropic::messages()->create(budgetRequest());
    });

    $job->assertReleased(21600);
    Http::assertSentCount(1);
});

test('a job on the sync queue gets the exception, since nothing would run it again', function () {
    $job = budgetJob();
    $job->setJob(new SyncJob(app(), '{}', 'sync', 'sync'));
    $budget = Anthropic::budget('team:3')->perDay(100);
    $budget->charge(['input_tokens' => 100, 'output_tokens' => 0]);

    expect(fn () => (new TokenBudget($budget))->handle($job, fn () => null))->toThrow(BudgetExceeded::class);
});

test('during() holds only the calls its callback makes, so a loop over users charges each their own', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Anthropic::budget('user:8')->perDay(1000)->clear();

    foreach (['user:7', 'user:8'] as $key) {
        Anthropic::budget($key)->perDay(1000)->during(fn () => Anthropic::messages()->create(budgetRequest()));
    }

    expect(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(870)
        ->and(Anthropic::budget('user:8')->perDay(1000)->remaining())->toBe(870);

    // and nothing is enforced after
    Anthropic::messages()->create(budgetRequest());

    expect(Anthropic::budget('user:8')->perDay(1000)->remaining())->toBe(870);
});

test('a job middleware without a budget of its own releases a job for the budgets it inherited', function () {
    $job = budgetJob();
    $job->withFakeQueueInteractions();
    Anthropic::budget('user:7')->perDay(100)->enforce()->charge(['input_tokens' => 100, 'output_tokens' => 0]);
    $ran = false;

    (new TokenBudget)->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse();
    $job->assertReleased();
});

test('every counter read needs a size', function () {
    expect(fn () => Anthropic::budget('user:7')->remaining())->toThrow(LogicException::class)
        ->and(fn () => Anthropic::budget('user:7')->charge(10))->toThrow(LogicException::class);
});

test('a call is charged even when a ResponseReceived listener throws', function () {
    Http::fake(['*' => Http::response(budgetAnswer())]);
    Event::listen(ResponseReceived::class, fn () => throw new RuntimeException('listener'));
    Anthropic::budget('user:7')->perDay(1000)->enforce();

    expect(fn () => Anthropic::messages()->create(budgetRequest()))->toThrow(RuntimeException::class, 'listener')
        ->and(Anthropic::budget('user:7')->perDay(1000)->remaining())->toBe(870);
});
