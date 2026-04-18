<?php

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Anthropic\Laravel\ServiceProvider;
use Illuminate\Config\Repository;

it('binds the client on the container', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
        ],
    ]));

    (new ServiceProvider($app))->register();

    expect($app->get(Client::class))->toBeInstanceOf(Client::class);
});

it('binds the client on the container as singleton', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
        ],
    ]));

    (new ServiceProvider($app))->register();

    $client = $app->get(Client::class);

    expect($app->get(Client::class))->toBe($client);
});

it('requires an api key', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([]));

    (new ServiceProvider($app))->register();

    $app->get(Client::class);
})->throws(
    ApiKeyIsMissing::class,
    'The Anthropic API Key is missing. Please publish the [anthropic.php] configuration file and set the [api_key].',
);

it('provides', function () {
    $app = app();

    $provides = (new ServiceProvider($app))->provides();

    expect($provides)->toBe([
        Client::class,
        ClientContract::class,
        'anthropic',
    ]);
});

it('sets the anthropic-beta header when config.beta has values', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'beta' => ['files-api-2025-04-14', 'extended-cache-ttl-2025-04-11'],
        ],
    ]));

    (new ServiceProvider($app))->register();

    expect(clientHeaders($app->get(Client::class)))
        ->toHaveKey('anthropic-beta', 'files-api-2025-04-14,extended-cache-ttl-2025-04-11');
});

it('does not set the anthropic-beta header when config.beta is empty or missing', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'beta' => [],
        ],
    ]));

    (new ServiceProvider($app))->register();

    expect(clientHeaders($app->get(Client::class)))->not->toHaveKey('anthropic-beta');
});

it('ignores non-string entries in config.beta', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'beta' => ['files-api-2025-04-14', 42, null, '', '  '],
        ],
    ]));

    (new ServiceProvider($app))->register();

    expect(clientHeaders($app->get(Client::class)))
        ->toHaveKey('anthropic-beta', 'files-api-2025-04-14');
});

/**
 * Extracts the headers value object from a resolved Client via reflection.
 *
 * @return array<string, string>
 */
function clientHeaders(Client $client): array
{
    $transporterProperty = new ReflectionProperty($client, 'transporter');
    $transporter = $transporterProperty->getValue($client);

    $headersProperty = new ReflectionProperty($transporter, 'headers');
    $headers = $headersProperty->getValue($transporter);

    /** @var array<string, string> $array */
    $array = $headers->toArray();

    return $array;
}
