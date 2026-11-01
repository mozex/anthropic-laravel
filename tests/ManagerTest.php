<?php

declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\AnthropicServiceProvider;
use Anthropic\Laravel\Events\RequestSending;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\ServiceProvider;
use Anthropic\Laravel\Testing\AnthropicFake;
use Anthropic\Resources\Messages;
use Anthropic\Resources\Models;
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Responses\Models\RetrieveResponse;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The headers a resolved client sends.
 *
 * @return array<string, string>
 */
function managerHeaders(ClientContract $client): array
{
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);

    return (new ReflectionProperty($transporter, 'headers'))->getValue($transporter)->toArray();
}

function managerBaseUri(ClientContract $client): string
{
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);

    return (new ReflectionProperty($transporter, 'baseUri'))->getValue($transporter)->toString();
}

beforeEach(function () {
    config([
        'anthropic.api_key' => 'sk-ant-default',
        'anthropic.workspace_id' => 'wrkspc_011CZkZaBF1tNoB5wlCeusgy',
        'anthropic.connections' => ['support' => ['api_key' => 'sk-ant-support', 'beta' => ['compact-2026-09-04']]],
    ]);
});

afterEach(function () {
    Facade::clearResolvedInstances();
    AnthropicManager::flushMacros();
});

test('the facade\'s root is the manager, and its default connection is the bound client', function () {
    expect(Anthropic::getFacadeRoot())->toBeInstanceOf(AnthropicManager::class)
        ->and(Anthropic::connection())->toBe(app(ClientContract::class))
        ->and(app('anthropic'))->toBe(app(ClientContract::class))
        ->and(app(Client::class))->toBe(app(ClientContract::class))
        ->and(Anthropic::models())->toBeInstanceOf(Models::class);
});

test('a named connection takes what it doesn\'t set from the top level', function () {
    $support = Anthropic::connection('support');

    expect(managerHeaders($support))->toMatchArray([
        'x-api-key' => 'sk-ant-support',
        'anthropic-workspace-id' => 'wrkspc_011CZkZaBF1tNoB5wlCeusgy',
        'anthropic-beta' => 'compact-2026-09-04',
    ])
        ->and(managerHeaders(Anthropic::connection()))->toMatchArray(['x-api-key' => 'sk-ant-default'])
        // cached, like a database connection
        ->and(Anthropic::connection('support'))->toBe($support);
});

test('an unknown connection throws', function () {
    expect(fn () => Anthropic::connection('missing'))->toThrow(InvalidArgumentException::class, 'Instance [missing] is not defined.');
});

test('a connection that sets either credential inherits neither', function () {
    config(['anthropic.connections.wif' => ['auth_token' => 'sk-ant-oat01-token']]);

    expect(managerHeaders(Anthropic::connection('wif')))->toMatchArray(['Authorization' => 'Bearer sk-ant-oat01-token'])
        ->and(managerHeaders(Anthropic::connection('wif')))->not->toHaveKey('x-api-key');
});

test("a connection whose key is unset or blank throws, naming it, and never takes laravel/ai's key", function (?string $key) {
    config([
        'anthropic.connections.support' => ['api_key' => $key],
        'ai.providers.anthropic' => ['key' => 'sk-ant-laravel-ai', 'url' => 'https://gateway.example.com/anthropic/v1'],
    ]);

    expect(fn () => Anthropic::connection('support'))->toThrow(ApiKeyIsMissing::class, '[support] connection');
})->with([
    'unset' => [null],
    'an empty .env line' => [''],
]);

test('a connection the config doesn\'t hold throws under the fake too', function () {
    Anthropic::fake();

    expect(fn () => Anthropic::connection('suport'))->toThrow(InvalidArgumentException::class, 'Instance [suport] is not defined.');
});

test("the manager's own names are its clients, not connections, faked or not", function () {
    config(['anthropic.connections.organization' => ['api_key' => 'sk-ant-other']]);

    expect(fn () => Anthropic::connection('organization'))->toThrow(InvalidArgumentException::class, 'Anthropic::organization()');

    Anthropic::fake();

    expect(fn () => Anthropic::connection('organization'))->toThrow(InvalidArgumentException::class, 'Anthropic::organization()');
});

test('withApiKey() builds a new client of the default settings every time', function () {
    $tenant = Anthropic::withApiKey('sk-ant-tenant');

    expect(managerHeaders($tenant))->toMatchArray(['x-api-key' => 'sk-ant-tenant'])
        // the default workspace belongs to the app's key: the API answers a tenant's key sent with it with a 404
        ->and(managerHeaders($tenant))->not->toHaveKey('anthropic-workspace-id')
        ->and(Anthropic::withApiKey('sk-ant-tenant'))->not->toBe($tenant);
});

test('a blank key for withApiKey() throws, faked or not', function () {
    expect(fn () => Anthropic::withApiKey(' '))->toThrow(ApiKeyIsMissing::class, 'withApiKey() is blank');

    Anthropic::fake();

    expect(fn () => Anthropic::withApiKey(''))->toThrow(ApiKeyIsMissing::class, 'withApiKey() is blank');
});

/**
 * Whether the value, or any array inside it, holds one of the texts.
 *
 * @param  list<string>  $texts
 */
function traceHolds(mixed $value, array $texts): bool
{
    if (is_string($value)) {
        return array_filter($texts, fn (string $text): bool => str_contains($value, $text)) !== [];
    }

    return is_array($value) && array_filter($value, fn (mixed $item): bool => traceHolds($item, $texts)) !== [];
}

test('a config error keeps the keys out of the trace', function (array $config, Closure $build, string $message) {
    config($config);
    $ignoreArgs = (string) ini_get('zend.exception_ignore_args');

    // php.ini-production leaves the arguments out, but Flare turns that off at runtime to collect them.
    ini_set('zend.exception_ignore_args', '0');

    try {
        $build();
    } catch (Throwable $exception) {
    } finally {
        ini_set('zend.exception_ignore_args', $ignoreArgs);
    }

    // The frames from the call the closure makes in, the facade's included; the ones above it are this test's and Pest's.
    $trace = $exception->getTrace();
    $call = array_key_first(array_filter($trace, fn (array $frame): bool => ($frame['file'] ?? null) === __FILE__));
    $args = array_column(array_slice($trace, 0, (int) $call + 1), 'args');

    expect($exception->getMessage())->toContain($message)
        // the arguments were kept, so the check below has something to look at
        ->and(array_filter($args))->not->toBe([])
        ->and(traceHolds($args, ['sk-ant-default', 'sk-ant-support', 'sk-ant-tenant']))->toBeFalse();
})->with([
    'the default connection' => [['anthropic.max_retries' => 'many'], fn () => app(ClientContract::class), 'max_retries'],
    'a named connection' => [['anthropic.connections.support.max_retries' => 'many'], fn () => Anthropic::connection('support'), 'max_retries'],
    'a tenant key' => [['anthropic.max_retries' => 'many'], fn () => Anthropic::withApiKey('sk-ant-tenant'), 'max_retries'],
    'a request option' => [[], fn () => Anthropic::withOptions(['headers' => ['x-api-key' => 'sk-ant-tenant'], 'max_retries' => 'two']), 'max_retries'],
    'client middleware that isn\'t callable' => [['anthropic.middleware' => [5]], fn () => app(ClientContract::class), 'anthropic.middleware'],
    'Guzzle middleware that isn\'t callable' => [['anthropic.http.middleware' => [5]], fn () => app(ClientContract::class), 'anthropic.http.middleware'],
]);

test('the organization, enterprise and compliance clients use their own keys, compliance as a bearer token', function () {
    config(['anthropic.admin_key' => 'sk-ant-admin01-key', 'anthropic.enterprise_key' => 'sk-ant-enterprise', 'anthropic.compliance_key' => 'sk-ant-compliance']);

    expect(managerHeaders(Anthropic::organization()))->toMatchArray(['x-api-key' => 'sk-ant-admin01-key'])
        // an organization's key belongs to no workspace
        ->and(managerHeaders(Anthropic::organization()))->not->toHaveKey('anthropic-workspace-id')
        ->and(managerHeaders(Anthropic::enterprise()))->toMatchArray(['x-api-key' => 'sk-ant-enterprise'])
        ->and(managerHeaders(Anthropic::compliance()))->toMatchArray(['Authorization' => 'Bearer sk-ant-compliance'])
        ->and(managerHeaders(Anthropic::compliance()))->not->toHaveKey('x-api-key')
        ->and(Anthropic::organization())->toBe(Anthropic::organization());
});

test('a manager-only connection without its key throws, naming the variable', function () {
    expect(fn () => Anthropic::organization())->toThrow(ApiKeyIsMissing::class, 'ANTHROPIC_ADMIN_KEY');
});

test('one fake answers every connection and a type-hinted manager, recording which connection each call used', function () {
    $fake = Anthropic::fake([CreateResponse::fake(), CreateResponse::fake(), RetrieveResponse::fake(), CreateResponse::fake(), CreateResponse::fake()]);

    Anthropic::messages()->create(['model' => 'claude-opus-5-5']);
    Anthropic::connection('support')->messages()->create(['model' => 'claude-opus-5-5']);
    Anthropic::organization()->models()->retrieve('claude-opus-5-5');
    app(AnthropicManager::class)->withApiKey('sk-ant-tenant')->messages()->create(['model' => 'claude-opus-5-5']);
    // code that type-hints the client gets the fake itself
    app(ClientContract::class)->messages()->create(['model' => 'claude-opus-5-5']);

    $fake->assertSent(Messages::class, 4);
    $fake->assertSentOn('default', Messages::class, 3);
    $fake->assertSentOn('support', Messages::class, 1);
    $fake->assertSentOn('organization', Models::class);
    $fake->assertNotSentOn('organization', Messages::class);

    // the inherited instance() answers from the fake too
    expect(Anthropic::instance('support'))->toBeInstanceOf(AnthropicFake::class);

    expect(app(ClientContract::class))->toBe($fake)
        ->and(serialize($fake))->not->toContain('sk-ant-tenant');
});

test('a macro on the facade reaches the manager, faked or not', function () {
    AnthropicManager::macro('support', fn () => $this->connection('support'));

    expect(managerHeaders(Anthropic::support()))->toMatchArray(['x-api-key' => 'sk-ant-support']);

    Anthropic::fake([CreateResponse::fake()]);

    Anthropic::support()->messages()->create(['model' => 'claude-opus-5-5']);

    Anthropic::assertSentOn('support', Messages::class);
});

test('a config published before connections existed still works', function () {
    $published = config('anthropic');
    unset($published['connections']);
    config(['anthropic' => $published]);

    expect(managerHeaders(Anthropic::connection()))->toMatchArray(['x-api-key' => 'sk-ant-default'])
        ->and(fn () => Anthropic::connection('support'))->toThrow(InvalidArgumentException::class);
});

test("an app with only laravel/ai's Anthropic key gets a client of its key and URL, and so do tenant and admin keys", function () {
    // composed: a gateway URL, so the test fails if laravel/ai's URL were ignored or given a second /v1
    config(['anthropic.api_key' => null, 'anthropic.admin_key' => 'sk-ant-admin01-key', 'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'sk-ant-laravel-ai', 'url' => 'https://gateway.example.com/anthropic/v1']]);

    $client = app(ClientContract::class);

    expect(managerHeaders($client))->toMatchArray(['x-api-key' => 'sk-ant-laravel-ai'])
        ->and(managerBaseUri($client))->toBe('https://gateway.example.com/anthropic/v1/')
        ->and(managerBaseUri(Anthropic::withApiKey('sk-ant-tenant')))->toBe('https://gateway.example.com/anthropic/v1/')
        ->and(managerHeaders(Anthropic::withApiKey('sk-ant-tenant')))->toMatchArray(['x-api-key' => 'sk-ant-tenant'])
        ->and(managerBaseUri(Anthropic::organization()))->toBe('https://gateway.example.com/anthropic/v1/');
});

test("an empty ANTHROPIC_API_KEY line, as anthropic:install writes it, still takes laravel/ai's key", function () {
    config(['anthropic.api_key' => '', 'ai.providers.anthropic' => ['key' => 'sk-ant-laravel-ai', 'url' => 'https://gateway.example.com/anthropic/v1']]);

    $client = app(ClientContract::class);

    expect(managerHeaders($client))->toMatchArray(['x-api-key' => 'sk-ant-laravel-ai'])
        ->and(managerBaseUri($client))->toBe('https://gateway.example.com/anthropic/v1/');
});

test('this package\'s own key wins over laravel/ai\'s', function () {
    config(['ai.providers.anthropic' => ['key' => 'sk-ant-laravel-ai', 'url' => 'https://gateway.example.com/v1']]);

    $client = app(ClientContract::class);

    expect(managerHeaders($client))->toMatchArray(['x-api-key' => 'sk-ant-default'])
        ->and(managerBaseUri($client))->toBe('https://api.anthropic.com/v1/');
});

test('an app\'s extend() of the client contract reaches the facade', function () {
    app()->extend(ClientContract::class, fn (ClientContract $client) => new AnthropicFake([RetrieveResponse::fake()]));

    expect(Anthropic::connection())->toBeInstanceOf(AnthropicFake::class)
        ->and(Anthropic::models()->retrieve('claude-opus-5-5'))->toBeInstanceOf(RetrieveResponse::class);
});

test("cached connections are forgotten between a worker's jobs and Octane's requests", function (Closure $tick) {
    config(['anthropic.admin_key' => 'sk-ant-admin01-key']);
    $support = Anthropic::connection('support');
    $organization = Anthropic::organization();
    $default = Anthropic::connection();

    $tick();

    expect(Anthropic::connection('support'))->not->toBe($support)
        ->and(Anthropic::organization())->not->toBe($organization)
        // the default connection is the container's binding, kept as it was resolved
        ->and(Anthropic::connection())->toBe($default);
})->with([
    'a queue loop' => [fn () => event(new Looping('redis', 'default'))],
    'an Octane operation' => [fn () => event('Laravel\Octane\Contracts\OperationTerminated')],
]);

test('a fresh app registers the about entry and the forgetting listeners, and a loop that never used the client never builds the manager', function () {
    AboutCommand::flushState();
    $previous = Container::getInstance();
    $app = new Application(dirname(__DIR__));
    $app->instance('config', new Repository([]));
    $app->register(new EventServiceProvider($app));
    $app->addDeferredServices([AnthropicManager::class => ServiceProvider::class, ClientContract::class => ServiceProvider::class]);
    $app->register(AnthropicServiceProvider::class);
    $app->boot();

    $app->make('events')->dispatch(new Looping('redis', 'default'));

    expect($app->resolved(AnthropicManager::class))->toBeFalse()
        ->and($app->resolved(ClientContract::class))->toBeFalse()
        ->and((new ReflectionProperty(AboutCommand::class, 'customDataResolvers'))->getValue())->toHaveCount(1);

    Container::setInstance($previous);
});

test('each connection\'s events carry its name, and its own middleware runs', function () {
    config([
        'anthropic.http.laravel_client' => true,
        'anthropic.connections.support.middleware' => [fn (RequestInterface $request, Closure $next) => $next($request->withHeader('X-Support', 'yes'))],
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray())]);
    $connections = new ArrayObject;
    Event::listen(fn (RequestSending $event) => $connections->append($event->connection));

    Anthropic::messages()->create(['model' => 'claude-opus-5-5']);
    Anthropic::connection('support')->messages()->create(['model' => 'claude-opus-5-5']);

    expect($connections->getArrayCopy())->toBe(['default', 'support']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Support', 'yes'));
    // the default connection's request went without it
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Support'));
    Http::assertSentCount(2);
});

test("the top-level middleware runs on the default connection, and an entry that isn't one throws", function () {
    $seen = new ArrayObject;
    config(['anthropic.middleware' => [function (RequestInterface $request, Closure $next) use ($seen) {
        $seen->append($request->getUri()->getPath());

        return $next($request);
    }], 'anthropic.http.laravel_client' => true]);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray())]);

    Anthropic::messages()->create(['model' => 'claude-opus-5-5']);

    expect($seen->getArrayCopy())->toBe(['/v1/messages']);

    config(['anthropic.connections.broken' => ['api_key' => 'sk-ant-broken', 'middleware' => [5]]]);

    expect(fn () => Anthropic::connection('broken'))->toThrow(InvalidArgumentException::class, 'anthropic.middleware');
});
