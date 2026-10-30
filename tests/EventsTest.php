<?php

declare(strict_types=1);

use Anthropic\Contracts\ClientContract;
use Anthropic\Exceptions\ConnectionException;
use Anthropic\Exceptions\RateLimitException;
use Anthropic\Laravel\Events\RateLimited;
use Anthropic\Laravel\Events\RequestFailed;
use Anthropic\Laravel\Events\RequestSending;
use Anthropic\Laravel\Events\ResponseReceived;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Files\FileResponse;
use Anthropic\Responses\Messages\CreateResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Events\ConnectionFailed as LaravelConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending as LaravelRequestSending;
use Illuminate\Http\Client\Events\ResponseReceived as LaravelResponseReceived;
use Illuminate\Http\Client\Request as LaravelRequest;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The Messages answer of the client's own fixture.
 *
 * @return array<string, mixed>
 */
function eventsMessage(): array
{
    return CreateResponse::fake()->toArray();
}

/**
 * A request the tests send.
 *
 * @return array<string, mixed>
 */
function eventsRequest(string $model = 'claude-opus-5-5'): array
{
    return ['model' => $model, 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello']]];
}

/**
 * Records every event of the given classes as it's dispatched.
 *
 * @param  list<class-string>  $classes
 * @return ArrayObject<int, object>
 */
function eventsRecorded(array $classes): ArrayObject
{
    $recorded = new ArrayObject;

    foreach ($classes as $class) {
        Event::listen($class, function (object $event) use ($recorded): void {
            $recorded[] = $event;
        });
    }

    return $recorded;
}

/**
 * Whether a value holds only scalars, nulls and arrays of them, as a queued listener needs.
 */
function eventsSerializable(mixed $value): bool
{
    if (is_array($value)) {
        return array_filter($value, fn (mixed $item): bool => ! eventsSerializable($item)) === [];
    }

    return $value === null || is_scalar($value);
}

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
});

// A fake left on the facade would answer the tests of other files.
afterEach(fn () => Facade::clearResolvedInstances());

test('each attempt dispatches its events with serializable data and no key', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage(), 200, ['request-id' => 'req_018EeWyXxfu5pfWkrYcMdjWG'])]);
    $recorded = eventsRecorded([RequestSending::class, ResponseReceived::class, RequestFailed::class]);

    Anthropic::messages()->create(eventsRequest());

    [$sending, $received] = $recorded->getArrayCopy();

    expect($recorded)->toHaveCount(2)
        ->and($sending)->toBeInstanceOf(RequestSending::class)
        ->and($sending->connection)->toBe('default')
        ->and($sending->method)->toBe('POST')
        ->and($sending->uri)->toBe('https://api.anthropic.com/v1/messages')
        ->and($sending->body)->toBe(eventsRequest())
        ->and(array_change_key_case($sending->headers))->not->toHaveKey('x-api-key')
        ->and(json_encode($sending))->not->toContain('sk-ant-test')
        ->and($received)->toBeInstanceOf(ResponseReceived::class)
        ->and($received->status)->toBe(200)
        ->and($received->usage)->toBe(eventsMessage()['usage'])
        ->and($received->meta['request-id'])->toBe('req_018EeWyXxfu5pfWkrYcMdjWG')
        ->and($received->durationMs)->toBeInt();

    foreach ($recorded as $event) {
        expect(eventsSerializable(get_object_vars($event)))->toBeTrue()
            ->and(unserialize(serialize($event)))->toEqual($event);
    }
});

test('a 429 dispatches RequestFailed and RateLimited, with the spend cap error code', function () {
    // the Message Batches page's spend-cap error, as a 429 would carry it
    Http::fake(['api.anthropic.com/*' => Http::response([
        'type' => 'error',
        'error' => [
            'type' => 'rate_limit_error',
            'message' => "You have reached your API usage limits: your organization has crossed its monthly API usage threshold, set based on your organization's API tier. You will regain access on 2026-09-01 at 00:00 UTC.",
            'details' => ['error_code' => 'enforced_spend_limit_reached'],
        ],
        'request_id' => 'req_018EeWyXxfu5pfWkrYcMdjWG',
    ], 429)]);
    $recorded = eventsRecorded([RequestFailed::class, RateLimited::class]);

    expect(fn () => Anthropic::messages()->create(eventsRequest()))
        ->toThrow(RateLimitException::class);

    [$failed, $limited] = $recorded->getArrayCopy();

    expect($failed->status)->toBe(429)
        ->and($failed->errorType)->toBe('rate_limit_error')
        ->and($failed->requestId)->toBe('req_018EeWyXxfu5pfWkrYcMdjWG')
        ->and($limited)->toBeInstanceOf(RateLimited::class)
        ->and($limited->errorCode)->toBe('enforced_spend_limit_reached')
        ->and($limited->retryAfter)->toBeNull();
});

test('a connection failure dispatches RequestFailed without a status', function () {
    config(['anthropic.http.laravel_client' => false, 'anthropic.base_url' => 'http://127.0.0.1:9', 'anthropic.request_timeout' => 2]);
    $recorded = eventsRecorded([RequestFailed::class]);

    expect(fn () => Anthropic::models()->list())->toThrow(ConnectionException::class);

    expect($recorded[0]->status)->toBeNull()
        ->and($recorded[0]->errorType)->toBe('connection_error');
});

test('Http::fake() sees the calls only with laravel_client on', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);

    Anthropic::messages()->create(eventsRequest());

    Http::assertSent(fn (LaravelRequest $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages' && $request['model'] === 'claude-opus-5-5');
});

test('the client stays off Laravel\'s HTTP client by default', function () {
    config(['anthropic.http.laravel_client' => false, 'anthropic.base_url' => 'http://127.0.0.1:9', 'anthropic.request_timeout' => 2]);
    Http::fake();

    expect(fn () => Anthropic::models()->list())->toThrow(ConnectionException::class);

    Http::assertNothingSent();
});

test('Laravel\'s own client events are dispatched only with laravel_events on', function (bool $on, int $expected) {
    config(['anthropic.http.laravel_events' => $on]);
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);
    $recorded = eventsRecorded([LaravelResponseReceived::class]);

    Anthropic::messages()->create(eventsRequest());

    expect($recorded)->toHaveCount($expected);

    if ($expected === 1) {
        expect($recorded[0]->response->json('model'))->toBe(eventsMessage()['model']);
    }
})->with([
    'off' => [false, 0],
    'on' => [true, 1],
]);

test('a connection failure dispatches Laravel\'s ConnectionFailed with laravel_events on', function () {
    config(['anthropic.http.laravel_client' => false, 'anthropic.http.laravel_events' => true, 'anthropic.base_url' => 'http://127.0.0.1:9', 'anthropic.request_timeout' => 2]);
    $recorded = eventsRecorded([LaravelConnectionFailed::class]);

    expect(fn () => Anthropic::models()->list())->toThrow(ConnectionException::class);

    expect($recorded)->toHaveCount(1);
});

test('nothing is dispatched to a fake', function () {
    Anthropic::fake([CreateResponse::fake()]);
    $recorded = eventsRecorded([RequestSending::class, ResponseReceived::class]);

    Anthropic::messages()->create(eventsRequest());

    expect($recorded)->toHaveCount(0);
});

test('a retried request dispatches its events once per attempt', function () {
    config(['anthropic.max_retries' => 1]);
    // composed: the overloaded error, as the client's typed-error tests compose it
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529, ['retry-after-ms' => '1'])
        ->push(eventsMessage())]);
    $recorded = eventsRecorded([RequestSending::class, ResponseReceived::class, RequestFailed::class]);

    Anthropic::messages()->create(eventsRequest());

    expect(array_map(fn (object $event): string => class_basename($event), $recorded->getArrayCopy()))
        ->toBe(['RequestSending', 'ResponseReceived', 'RequestFailed', 'RequestSending', 'ResponseReceived']);
});

test('each fallback attempt is an event of its own', function () {
    config(['anthropic.fallback_models' => ['claude-opus-4-8']]);
    // composed: the refusals page's refusal, then the fallback model's answer
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['id' => 'msg_01XFUDYJgAACzvnptvVoYEL', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-fable-5', 'content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'This request was declined because it could enable cyber harm.'], 'usage' => ['input_tokens' => 412, 'output_tokens' => 0]])
        ->push([...eventsMessage(), 'model' => 'claude-opus-4-8'])]);
    $recorded = eventsRecorded([RequestSending::class]);

    Anthropic::messages()->create(eventsRequest('claude-fable-5'));

    expect(array_map(fn (RequestSending $event): string => $event->body['model'], $recorded->getArrayCopy()))->toBe(['claude-fable-5', 'claude-opus-4-8']);
});

test('a streamed answer has no body or usage, gets Laravel\'s events as a pair, and still streams', function () {
    config(['anthropic.http.laravel_events' => true]);
    $stream = file_get_contents(__DIR__.'/../vendor/mozex/anthropic-php/src/Testing/Responses/Fixtures/Messages/CreateStreamedResponseFixture.txt');
    Http::fake(['api.anthropic.com/*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);
    $ours = eventsRecorded([ResponseReceived::class]);
    $laravelEvents = eventsRecorded([LaravelRequestSending::class, LaravelResponseReceived::class]);

    $events = iterator_to_array(Anthropic::messages()->createStreamed([...eventsRequest(), 'stream' => true]));

    [$received] = $ours->getArrayCopy();

    expect($received->body)->toBeNull()
        ->and($received->usage)->toBeNull()
        ->and(array_map(fn (object $event): string => $event::class, $laravelEvents->getArrayCopy()))
        ->toBe([LaravelRequestSending::class, LaravelResponseReceived::class])
        ->and($events)->not->toBe([]);
});

test('no event carries a credential, the auth token and a gateway key included', function () {
    config(['anthropic.api_key' => null, 'anthropic.auth_token' => 'sk-ant-oat01-token', 'anthropic.headers' => ['X-Gateway-Key' => 'gw-secret'], 'anthropic.http.laravel_events' => true]);
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);
    $recorded = eventsRecorded([RequestSending::class, LaravelRequestSending::class, LaravelResponseReceived::class]);

    Anthropic::messages()->create(eventsRequest());

    [$sending, $laravelSending, $laravelReceived] = $recorded->getArrayCopy();

    expect(array_change_key_case($sending->headers))->not->toHaveKeys(['authorization', 'x-gateway-key'])
        ->and(serialize($sending))->not->toContain('sk-ant-oat01-token')
        ->and($laravelSending->request->hasHeader('Authorization'))->toBeFalse()
        ->and($laravelReceived->request->hasHeader('X-Gateway-Key'))->toBeFalse()
        ->and($laravelReceived->request->hasHeader('Content-Type'))->toBeTrue();
});

test('Laravel\'s events carry no API key', function () {
    config(['anthropic.http.laravel_events' => true]);
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);
    $recorded = eventsRecorded([LaravelResponseReceived::class]);

    Anthropic::messages()->create(eventsRequest());

    expect($recorded[0]->request->hasHeader('x-api-key'))->toBeFalse();
});

test('an upload is never read into an event', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(FileResponse::fake()->toArray())]);
    $recorded = eventsRecorded([RequestSending::class]);

    Anthropic::files()->upload(['file' => Utils::streamFor('%PDF-1.4')]);

    expect($recorded[0]->body)->toBeNull();
});

test('an Http::fake() set after the client was built still applies', function () {
    app(ClientContract::class);

    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);

    expect(Anthropic::messages()->create(eventsRequest())->id)->toBe(eventsMessage()['id']);
});

test('Http::preventStrayRequests() set after the client was built still applies', function () {
    app(ClientContract::class);

    Http::preventStrayRequests();

    expect(fn () => Anthropic::messages()->create(eventsRequest()))->toThrow(StrayRequestException::class);
});

test('the app\'s own middleware runs before the fake answers', function () {
    config(['anthropic.http.middleware' => [fn (callable $handler): callable => fn ($request, array $options) => $handler($request->withHeader('X-App', 'demo'), $options)]]);
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);

    Anthropic::messages()->create(eventsRequest());

    Http::assertSent(fn (LaravelRequest $request): bool => $request->hasHeader('X-App', 'demo'));
});

test('an Event::fake() set after the client was built sees the events', function () {
    app(ClientContract::class);
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage())]);
    Event::fake([ResponseReceived::class]);

    Anthropic::messages()->create(eventsRequest());

    Event::assertDispatched(ResponseReceived::class, fn (ResponseReceived $event): bool => $event->status === 200);
});

test('a cookie a gateway sets never reaches an event', function () {
    // composed: a gateway's cookie on the answer
    Http::fake(['api.anthropic.com/*' => Http::response(eventsMessage(), 200, ['Set-Cookie' => 'session=gw-secret'])]);
    $recorded = eventsRecorded([ResponseReceived::class]);

    Anthropic::messages()->create(eventsRequest());

    expect(array_change_key_case($recorded[0]->headers))->not->toHaveKey('set-cookie');
});

test('a stray request still pairs Laravel\'s RequestSending, and fails as a transport error', function () {
    config(['anthropic.http.laravel_events' => true]);
    Http::preventStrayRequests();
    $recorded = eventsRecorded([LaravelRequestSending::class, LaravelConnectionFailed::class, RequestFailed::class]);

    expect(fn () => Anthropic::messages()->create(eventsRequest()))->toThrow(StrayRequestException::class);

    expect(array_map(fn (object $event): string => class_basename($event), $recorded->getArrayCopy()))->toBe(['RequestSending', 'RequestFailed', 'ConnectionFailed'])
        ->and($recorded[1]->errorType)->toBe('transport_error');
});
