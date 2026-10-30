<?php

declare(strict_types=1);

use Anthropic\Responses\Models\ListResponse;
use Anthropic\Responses\Models\RetrieveResponse;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // the app's queue is redis, so its retry_after is the one that must hold
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true, 'queue.default' => 'redis']);
});

afterEach(fn () => Facade::clearResolvedInstances());

test('a sound setup passes every check', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray())]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('Credential')
        ->expectsOutputToContain('https://api.anthropic.com/v1/')
        ->expectsOutputToContain('Queue [redis] retry_after')
        ->expectsOutputToContain('Everything checks out.')
        ->doesntExpectOutputToContain('sk-ant-test')
        ->assertSuccessful();

    // the API check spends no tokens: one models page
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/models?limit=1'));
});

test('a queue whose retry_after the longest call reaches fails, retries counted', function (array $config, string $message) {
    config($config);
    Http::fake(['api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray())]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain($message)
        ->expectsOutputToContain('Some checks failed.')
        ->assertFailed();
})->with([
    'below the request timeout' => [['anthropic.request_timeout' => 120], 'raise it above 120s'],
    'equal to it' => [['queue.connections.redis.retry_after' => 30], 'raise it above 30s'],
    // 3 attempts of 30s, and the client's waits of 0.5s and 1s between them
    'below the timeout times the attempts' => [['anthropic.max_retries' => 2], 'raise it above 91.5s'],
    'a connection without one, at Laravel\'s 60s' => [['anthropic.request_timeout' => 60, 'queue.connections.redis.retry_after' => null], 'Queue [redis] retry_after'],
]);

test('a request timeout of 0 fails, and a queue the app may not use only warns', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray())]);

    config(['anthropic.request_timeout' => 0]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('a call never times out')
        ->assertFailed();

    config(['anthropic.request_timeout' => 30, 'queue.connections.beanstalkd.retry_after' => 10]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('below 30s, if a job that calls Claude runs on it')
        ->assertSuccessful();
});

test('every fallback model counts toward the longest call', function () {
    config(['anthropic.request_timeout' => 50, 'anthropic.fallback_models' => ['claude-opus-4-8']]);
    Http::fake([
        'api.anthropic.com/v1/models/claude-opus-4-8' => Http::response(RetrieveResponse::fake(['id' => 'claude-opus-4-8'])->toArray()),
        'api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray()),
    ]);

    // two models of 50s each outlive the stock 90s retry_after
    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('100s (2 models of 50s)')
        ->expectsOutputToContain('raise it above 100s')
        ->assertFailed();
});

test('a named connection is checked with its own settings', function () {
    config(['anthropic.connections.support' => ['api_key' => 'sk-ant-support', 'request_timeout' => 120]]);
    Http::fake(['api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray())]);

    $this->artisan('anthropic:doctor', ['--connection' => 'support'])
        ->expectsOutputToContain('Checking the [support] connection.')
        ->expectsOutputToContain('raise it above 120s')
        ->assertFailed();
});

test('an answer that isn\'t the API\'s fails the API check, the rest still runs and the fallback models are skipped', function () {
    config(['anthropic.fallback_models' => ['claude-opus-4-8']]);
    // composed: a gateway's catch-all page
    Http::fake(['api.anthropic.com/*' => Http::response('<html><body>Welcome</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('FAIL Syntax error')
        ->expectsOutputToContain('Queue [redis] retry_after')
        ->doesntExpectOutputToContain('Fallback model')
        ->expectsOutputToContain('Some checks failed.')
        ->assertFailed();
});

test('offline, a missing credential only warns, so the queues are still checked', function () {
    config(['anthropic.api_key' => null]);
    Http::fake();

    $this->artisan('anthropic:doctor', ['--offline' => true])
        ->expectsOutputToContain('WARN The Anthropic API Key is missing.')
        ->expectsOutputToContain('Queue [redis] retry_after')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('a missing credential, an unknown connection and an API error fail', function (array $config, array $arguments, string $message) {
    config($config);
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'], 'request_id' => 'req_018EeWyXxfu5pfWkrYcMdjWG'], 401)]);

    $this->artisan('anthropic:doctor', $arguments)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'no credential' => [['anthropic.api_key' => null], [], 'The Anthropic API Key is missing.'],
    'an empty key line' => [['anthropic.api_key' => ''], [], 'The Anthropic API Key is missing.'],
    'a config value of the wrong type' => [['anthropic.max_retries' => 'many'], [], 'Configuration'],
    'an unknown connection' => [[], ['--connection' => 'support'], "The [support] connection isn't in config/anthropic.php."],
    // the errors page's request ID; composed: the 401 body
    'a rejected key' => [[], [], 'authentication_error: invalid x-api-key req_018EeWyXxfu5pfWkrYcMdjWG'],
]);

test('the fallback models are checked against their lifecycle', function (array $model, bool $passes, string $message) {
    config(['anthropic.fallback_models' => ['claude-opus-4-8']]);
    // composed: the stages the Models docs describe
    Http::fake([
        'api.anthropic.com/v1/models/claude-opus-4-8' => $model === [] ? Http::response(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: claude-opus-4-8']], 404) : Http::response(RetrieveResponse::fake(['id' => 'claude-opus-4-8', ...$model])->toArray()),
        'api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray()),
    ]);

    $command = $this->artisan('anthropic:doctor')->expectsOutputToContain($message);

    $passes ? $command->assertSuccessful() : $command->assertFailed();
})->with([
    'active' => [['lifecycle' => 'active'], true, 'OK active'],
    'deprecated' => [['lifecycle' => 'deprecated', 'retires_at' => '2999-02-15T00:00:00Z'], true, 'deprecated, retires on 2999-02-15'],
    'overdue' => [['lifecycle' => 'deprecated', 'retires_at' => '2000-01-01T00:00:00Z'], true, 'overdue to retire since 2000-01-01'],
    'retired' => [['lifecycle' => 'retired', 'retires_at' => '2026-01-05T00:00:00Z'], false, 'FAIL retired'],
    'unknown' => [[], false, 'not a model the API knows'],
]);

test('offline, nothing is sent, the fallback models included', function () {
    config(['anthropic.fallback_models' => ['claude-opus-4-8']]);
    Http::fake();

    $this->artisan('anthropic:doctor', ['--offline' => true])->assertSuccessful();

    Http::assertNothingSent();
});

test('about shows the versions, the credential\'s kind and what the client sends, never the key', function () {
    config(['anthropic.beta' => ['compact-2026-09-04'], 'anthropic.connections' => ['support' => ['api_key' => 'sk-ant-support']]]);

    $this->artisan('about', ['--only' => 'anthropic'])
        ->expectsOutputToContain('API key')
        ->expectsOutputToContain('compact-2026-09-04')
        ->expectsOutputToContain('default, support')
        ->doesntExpectOutputToContain('sk-ant-test')
        ->doesntExpectOutputToContain('sk-ant-support')
        ->assertSuccessful();
});

test('Horizon\'s supervisors are the ones it runs here, picked as Horizon picks them', function (array $horizon, ?string $message, ?string $absent) {
    if (! class_exists('Laravel\Horizon\Horizon')) {
        // Horizon isn't a dependency: a stand-in class is enough for the doctor's check.
        eval('namespace Laravel\Horizon; class Horizon {}');
    }

    config(['horizon' => $horizon]);
    Http::fake(['api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray())]);

    $command = $this->artisan('anthropic:doctor');
    $message === null ? $command->doesntExpectOutputToContain('Horizon [') : $command->expectsOutputToContain($message);

    if ($absent !== null) {
        $command->doesntExpectOutputToContain($absent);
    }

    // a short supervisor timeout only warns: a job's own $timeout wins over it
    $command->assertSuccessful();
})->with([
    // composed: Horizon's config shape; Testbench runs as "testing"
    'its own environment' => [['defaults' => ['supervisor-1' => ['timeout' => 60]], 'environments' => ['testing' => ['supervisor-1' => []]]], 'OK 60s', null],
    'a short timeout' => [['defaults' => ['supervisor-1' => ['timeout' => 20]], 'environments' => ['testing' => ['supervisor-1' => []]]], 'needs its own $timeout above that', null],
    'only production defined' => [['defaults' => ['supervisor-1' => ['timeout' => 20]], 'environments' => ['production' => ['supervisor-1' => []]]], null, null],
    'a wildcard first' => [['defaults' => ['supervisor-1' => ['timeout' => 20]], 'environments' => ['*' => ['supervisor-1' => ['timeout' => 600]], 'testing' => ['supervisor-1' => ['timeout' => 20]]]], 'OK 600s', 'WARN 20s'],
    'horizon.env over the app\'s' => [['env' => 'production', 'defaults' => ['supervisor-1' => ['timeout' => 20]], 'environments' => ['production' => ['supervisor-1' => ['timeout' => 300]]]], 'OK 300s', null],
    'no processes' => [['defaults' => ['supervisor-1' => ['timeout' => 20, 'maxProcesses' => 0]], 'environments' => ['testing' => ['supervisor-1' => []]]], null, null],
]);

test('a queue Horizon uses must hold the longest call, and a used SQS queue warns', function () {
    if (! class_exists('Laravel\Horizon\Horizon')) {
        eval('namespace Laravel\Horizon; class Horizon {}');
    }

    config([
        'queue.default' => 'sync',
        'queue.connections.database.retry_after' => 20,
        'queue.connections.sqs.driver' => 'sqs',
        // composed: a supervisor on the database queue, and SQS as the failover's member
        'horizon' => ['defaults' => ['supervisor-1' => ['connection' => 'database', 'timeout' => 60]], 'environments' => ['testing' => ['supervisor-1' => []]]],
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response(ListResponse::fake()->toArray())]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('FAIL 20s: raise it above 30s')
        ->doesntExpectOutputToContain('visibility timeout')
        ->assertFailed();

    config(['queue.default' => 'failover', 'queue.connections.failover' => ['driver' => 'failover', 'connections' => ['sqs', 'redis']], 'queue.connections.database.retry_after' => 90]);

    $this->artisan('anthropic:doctor')
        ->expectsOutputToContain('visibility timeout')
        ->assertSuccessful();
});

test('about shows a client the config can\'t build', function () {
    config(['anthropic.max_retries' => 'many']);

    $this->artisan('about', ['--only' => 'anthropic'])
        ->expectsOutputToContain('NOT BUILT')
        ->assertSuccessful();
});

test('about says a missing credential is missing', function () {
    config(['anthropic.api_key' => null]);

    $this->artisan('about', ['--only' => 'anthropic'])
        ->expectsOutputToContain('MISSING')
        ->assertSuccessful();
});
