<?php

declare(strict_types=1);

use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Events\ResponseReceived;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Messages\CreateResponse;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A request the tests send.
 *
 * @return array<string, mixed>
 */
function recorderRequest(): array
{
    return ['model' => 'claude-opus-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello']]];
}

beforeEach(function () {
    $this->cassette = sys_get_temp_dir().'/anthropic-laravel-recorder-'.bin2hex(random_bytes(6)).'/summary.json';
    config(['anthropic.http.laravel_client' => true]);
});

afterEach(function () {
    Facade::clearResolvedInstances();

    if (is_file($this->cassette)) {
        unlink($this->cassette);
        rmdir(dirname($this->cassette));
    }
});

test('Anthropic::record() takes over from a fake made before it', function () {
    config(['anthropic.api_key' => 'sk-ant-test']);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake(['id' => 'msg_recorded'])->toArray())]);

    Anthropic::fake();
    Anthropic::record($this->cassette);

    expect(Anthropic::messages()->create(recorderRequest())->id)->toBe('msg_recorded')
        ->and((string) file_get_contents($this->cassette))->toContain('msg_recorded');
});

test('recording without a cassette or a key throws, naming the file', function () {
    config(['anthropic.api_key' => null]);

    expect(fn () => Anthropic::record($this->cassette))->toThrow(RuntimeException::class, 'No cassette at ['.$this->cassette.'] to replay, and no key to record one with');
});

test('Anthropic::record() records the facade\'s calls once, then replays them with no HTTP call and no key', function () {
    config(['anthropic.api_key' => 'sk-ant-test']);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake(['id' => 'msg_recorded'])->toArray(), 200, ['request-id' => 'req_018EeWyXxfu5pfWkrYcMdjWG'])]);

    expect(Anthropic::record($this->cassette)->recording())->toBeTrue()
        ->and(Anthropic::messages()->create(recorderRequest())->id)->toBe('msg_recorded');

    // the replay: no key, and any request that reached Laravel's client would fail
    config(['anthropic.api_key' => null]);
    Http::preventStrayRequests();
    Http::fake(['*' => fn () => throw new RuntimeException('The replay sent a request.')]);
    $received = new ArrayObject;
    Event::listen(fn (ResponseReceived $event) => $received->append($event->meta['request-id'] ?? null));

    expect(Anthropic::record($this->cassette)->recording())->toBeFalse()
        ->and(Anthropic::messages()->create(recorderRequest())->id)->toBe('msg_recorded')
        // the events still fire, on the recorded answer
        ->and($received->getArrayCopy())->toBe(['req_018EeWyXxfu5pfWkrYcMdjWG'])
        ->and((string) file_get_contents($this->cassette))->not->toContain('sk-ant-test');
});

test('Anthropic::record() takes over from a fake in an app without the provider', function () {
    $container = Container::getInstance();
    $facadeApplication = Facade::getFacadeApplication();

    try {
        $app = new Application(dirname(__DIR__));
        $app->instance('config', new Repository(['anthropic' => ['api_key' => 'sk-ant-test']]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $fake = Anthropic::fake();
        Anthropic::record($this->cassette, record: true);

        expect(Anthropic::getFacadeRoot())->not->toBe($fake)
            ->and(Anthropic::getFacadeRoot())->toBe($app->make(ClientContract::class));
    } finally {
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($facadeApplication);
    }
});
