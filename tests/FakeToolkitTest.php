<?php

declare(strict_types=1);

use Anthropic\Contracts\ClientContract;
use Anthropic\Exceptions\RateLimitException;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Resources\Messages;
use Anthropic\Resources\Models;
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Responses\Models\RetrieveResponse;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.connections.support' => ['api_key' => 'sk-ant-support']]);
});

afterEach(fn () => Facade::clearResolvedInstances());

test('an empty Anthropic::fake() answers the facade, a connection and an injected client with default fakes', function () {
    $fake = Anthropic::fake();

    expect(Anthropic::messages()->create(['model' => 'claude-opus-5-5']))->toBeInstanceOf(CreateResponse::class)
        ->and(Anthropic::connection('support')->models()->retrieve('claude-opus-5-5'))->toBeInstanceOf(RetrieveResponse::class)
        ->and(app(ClientContract::class)->messages()->create(['model' => 'claude-opus-5-5']))->toBeInstanceOf(CreateResponse::class);

    $fake->assertSentTimes(Messages::class, 2);
    Anthropic::assertSentInOrder([[Messages::class, 'create'], [Models::class, 'retrieve'], Messages::class]);
});

test('preventStrayRequests() makes an unanswered facade call throw', function () {
    Anthropic::fake()->preventStrayRequests();

    expect(fn () => Anthropic::messages()->create(['model' => 'claude-opus-5-5']))
        ->toThrow(RuntimeException::class, 'No fake responses left. Messages::create() has none');
});

test('respondTo() answers every connection\'s matching calls', function () {
    Anthropic::fake()->respondTo([Models::class, 'retrieve'], RetrieveResponse::fake(['id' => 'claude-opus-5-5']));

    expect(Anthropic::models()->retrieve('claude-opus-5-5')->id)->toBe('claude-opus-5-5')
        ->and(Anthropic::connection('support')->models()->retrieve('claude-opus-5-5')->id)->toBe('claude-opus-5-5');

    Anthropic::assertSentOn('support', Models::class, 1);
});

test('a queued error fake reaches the code under test with its type, request ID and retry-after', function () {
    Anthropic::fake([RateLimitException::fake(headers: ['retry-after' => '30'])]);

    expect(fn () => Anthropic::messages()->create(['model' => 'claude-opus-5-5']))->toThrow(function (RateLimitException $e) {
        expect($e->getRetryAfter())->toBe(30)
            ->and($e->getStatusCode())->toBe(429)
            ->and($e->getErrorType())->toBe('rate_limit_error')
            ->and($e->getRequestId())->toBe('req_018EeWyXxfu5pfWkrYcMdjWG');
    });

    Anthropic::assertSent(Messages::class, 1);
});

test('the fake\'s methods reach the root through the facade', function () {
    Anthropic::fake();
    Anthropic::preventStrayRequests();
    Anthropic::respondTo([Models::class, 'retrieve'], RetrieveResponse::fake(['id' => 'claude-opus-5-5']));

    expect(Anthropic::connection('support')->models()->retrieve('claude-opus-5-5')->id)->toBe('claude-opus-5-5')
        ->and(fn () => Anthropic::messages()->create(['model' => 'claude-opus-5-5']))->toThrow(RuntimeException::class, 'No fake responses left. Messages::create() has none')
        ->and(Anthropic::sent(Models::class)[0]->connection())->toBe('support');
});

test('the Pest expectations read Anthropic::fake()', function () {
    $fake = Anthropic::fake();

    Anthropic::connection('support')->messages()->create(['model' => 'claude-opus-5-5']);

    expect($fake)->toHaveSent(Messages::class)
        ->toHaveSentOn('support', Messages::class)
        ->not->toHaveSent(Models::class);
});
