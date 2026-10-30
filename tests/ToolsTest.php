<?php

declare(strict_types=1);

use Anthropic\Exceptions\AuthenticationException;
use Anthropic\Exceptions\RequestTooLargeException;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Rules\FitsInTokens;
use Anthropic\Resources\Messages;
use Anthropic\Resources\Models;
use Anthropic\Responses\Messages\CountTokensResponse;
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Responses\Models\ListResponse;
use Anthropic\Testing\Blocks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.connections' => ['support' => ['api_key' => 'sk-ant-support']]]);
    Cache::flush();
});

test('anthropic:ping answers with a call that spends no tokens, on the connection asked', function () {
    Anthropic::fake([ListResponse::fake()]);

    $this->artisan('anthropic:ping', ['--connection' => 'support'])
        ->expectsOutputToContain('The Anthropic API answered in')
        ->assertSuccessful();

    Anthropic::assertSentOn('support', Models::class, fn (string $method, array $parameters = []): bool => $method === 'list' && $parameters === ['limit' => 1]);
});

test('anthropic:ping fails when the API refuses the key', function () {
    Anthropic::fake([AuthenticationException::fake()]);

    $this->artisan('anthropic:ping')->expectsOutputToContain(AuthenticationException::fake()->getMessage())->assertFailed();
});

test('anthropic:count-tokens counts a text, or a file, with a system prompt', function () {
    Anthropic::fake([CountTokensResponse::fake(), CountTokensResponse::fake()]);
    $file = tempnam(sys_get_temp_dir(), 'anthropic');
    file_put_contents($file, 'Hello from a file');

    $this->artisan('anthropic:count-tokens', ['text' => 'Hello!', '--model' => 'claude-sonnet-5-5', '--system' => 'Be brief.'])
        ->expectsOutputToContain('2,095')
        ->assertSuccessful();
    $this->artisan('anthropic:count-tokens', ['--file' => $file, '--model' => 'claude-sonnet-5-5'])->assertSuccessful();

    unlink($file);

    Anthropic::assertSentInOrder([
        fn ($request): bool => $request->args()[0] === ['model' => 'claude-sonnet-5-5', 'messages' => [['role' => 'user', 'content' => 'Hello!']], 'system' => 'Be brief.'],
        fn ($request): bool => $request->args()[0]['messages'][0]['content'] === 'Hello from a file',
    ]);
});

test('anthropic:count-tokens needs a model and a text', function (array $arguments, string $message) {
    Anthropic::fake()->preventStrayRequests();

    $this->artisan('anthropic:count-tokens', $arguments)->expectsOutputToContain($message)->assertFailed();
})->with([
    'no model' => [['text' => 'Hello!'], 'Pass the model'],
    'no text' => [['--model' => 'claude-sonnet-5-5'], 'Pass the text'],
    'a missing file' => [['--model' => 'claude-sonnet-5-5', '--file' => 'no-such-file.txt'], "can't be read"],
]);

test('anthropic:models lists every model with its input limit', function () {
    Anthropic::fake([ListResponse::fake()]);

    $this->artisan('anthropic:models')
        ->expectsOutputToContain('claude-sonnet-4-6')
        ->assertSuccessful();
});

test('anthropic:chat streams each answer and keeps the conversation, until an empty line', function () {
    Anthropic::fake([
        CreateStreamedResponse::fake(CreateResponse::fake(['content' => [Blocks::text('Hello! How can I help?')]])),
        CreateStreamedResponse::fake(CreateResponse::fake(['content' => [Blocks::text('Paris.')]])),
    ]);

    $this->artisan('anthropic:chat', ['--model' => 'claude-sonnet-5-5', '--system' => 'Be brief.'])
        ->expectsQuestion('You', 'Hi')
        ->expectsQuestion('You', 'What is the capital of France?')
        ->expectsQuestion('You', '')
        ->expectsOutputToContain('Hello! How can I help?')
        ->expectsOutputToContain('Paris.')
        ->assertSuccessful();

    Anthropic::assertSentTimes(Messages::class, 2);
    Anthropic::assertSent(Messages::class, fn (string $method, array $parameters): bool => $method === 'createStreamed'
        && $parameters['system'] === 'Be brief.'
        && $parameters['messages'] === [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello! How can I help?'],
            ['role' => 'user', 'content' => 'What is the capital of France?'],
        ]);
});

test('anthropic:chat drops a turn the API refused and goes on', function () {
    Anthropic::fake([AuthenticationException::fake(), CreateStreamedResponse::fake(CreateResponse::fake(['content' => [Blocks::text('Hello!')]]))]);

    $this->artisan('anthropic:chat', ['--model' => 'claude-sonnet-5-5'])
        ->expectsQuestion('You', 'Hi')
        ->expectsQuestion('You', 'Hi again')
        ->expectsQuestion('You', '')
        ->expectsOutputToContain('Hello!')
        ->assertSuccessful();

    Anthropic::assertSent(Messages::class, fn (string $method, array $parameters): bool => $parameters['messages'] === [['role' => 'user', 'content' => 'Hi again']]);
});

test('anthropic:chat leaves out a turn Claude answered without text', function () {
    // composed: an answer that ran out of tokens while thinking, which streams no text
    Anthropic::fake([
        CreateStreamedResponse::fake(CreateResponse::fake(['content' => [Blocks::thinking('Let me work through this.')], 'stop_reason' => 'max_tokens'])),
        CreateStreamedResponse::fake(CreateResponse::fake(['content' => [Blocks::text('Hello!')]])),
    ]);

    $this->artisan('anthropic:chat', ['--model' => 'claude-sonnet-5-5'])
        ->expectsQuestion('You', 'Something refused')
        ->expectsQuestion('You', 'Hi')
        ->expectsQuestion('You', '')
        ->expectsOutputToContain('without any text')
        ->assertSuccessful();

    Anthropic::assertSent(Messages::class, fn (string $method, array $parameters): bool => $parameters['messages'] === [['role' => 'user', 'content' => 'Hi']]);
});

test('anthropic:chat needs a model and a positive max-tokens', function () {
    Anthropic::fake()->preventStrayRequests();

    $this->artisan('anthropic:chat')->expectsOutputToContain('Pass the model')->assertFailed();
    $this->artisan('anthropic:chat', ['--model' => 'claude-sonnet-5-5', '--max-tokens' => '0'])->expectsOutputToContain('--max-tokens takes')->assertFailed();
});

test('models()->cached() keeps the list per connection and key, and asks the API once', function () {
    config(['anthropic.http.laravel_client' => true]);
    Http::fake(['*' => Http::response(ListResponse::fake()->toArray())]);

    $first = Anthropic::models()->cached();
    $again = Anthropic::models()->cached();

    expect($first[0]->id)->toBe('claude-sonnet-4-6')
        ->and($again[0]->displayName)->toBe($first[0]->displayName)
        ->and(Cache::has('anthropic:models:default'))->toBeTrue();
    Http::assertSentCount(1);

    Anthropic::connection('support')->models()->cached();
    Anthropic::withApiKey('sk-ant-tenant-key')->models()->cached();

    Http::assertSentCount(3);
    expect(Cache::has('anthropic:models:default:'.hash_hmac('sha256', 'sk-ant-tenant-key', (string) config('app.key'))))->toBeTrue();
});

test('models()->cached() under the fake never caches', function () {
    Anthropic::fake([ListResponse::fake(), ListResponse::fake()]);

    Anthropic::models()->cached();
    Anthropic::models()->cached();

    Anthropic::assertSentTimes(Models::class, 2);
    expect(Cache::has('anthropic:models:default'))->toBeFalse();
});

test('FitsInTokens passes a text within the limit and fails one over it, naming its count', function () {
    Anthropic::fake([CountTokensResponse::fake(), CountTokensResponse::fake()]);

    expect(Validator::make(['prompt' => 'Hello!'], ['prompt' => [new FitsInTokens(3000, 'claude-sonnet-5-5')]])->passes())->toBeTrue();

    $validator = Validator::make(['prompt' => 'Hello!'], ['prompt' => [(new FitsInTokens(2000, 'claude-sonnet-5-5'))->withSystem('Be brief.')->onConnection('support')]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('prompt'))->toBe('The prompt is 2095 tokens long, over the limit of 2000.');
    Anthropic::assertSentOn('support', Messages::class, fn (string $method, array $parameters): bool => $parameters['system'] === 'Be brief.');
});

test('FitsInTokens leaves an empty value to the other rules, fails a text too large to count, and throws when the count fails', function () {
    Anthropic::fake([RequestTooLargeException::fake(), AuthenticationException::fake()]);

    expect(Validator::make(['prompt' => '  '], ['prompt' => [new FitsInTokens(10, 'claude-sonnet-5-5')]])->passes())->toBeTrue();

    $tooLarge = Validator::make(['prompt' => 'Hello!'], ['prompt' => [new FitsInTokens(10, 'claude-sonnet-5-5')]]);
    expect($tooLarge->fails())->toBeTrue()
        ->and($tooLarge->errors()->first('prompt'))->toBe('The prompt is too long to count, over the limit of 10 tokens.');

    expect(fn () => Validator::make(['prompt' => 'Hello!'], ['prompt' => [new FitsInTokens(10, 'claude-sonnet-5-5')]])->passes())->toThrow(AuthenticationException::class);
});
