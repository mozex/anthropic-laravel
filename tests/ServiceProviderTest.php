<?php

declare(strict_types=1);

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

it('sends the configured api version', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'api_version' => '2026-10-21',
        ],
    ]));

    (new ServiceProvider($app))->register();

    expect(clientHeaders($app->get(Client::class)))->toHaveKey('anthropic-version', '2026-10-21');
});

it('keeps the client defaults when api_version and base_url are empty or missing', function (array $config) {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => ['api_key' => 'test', ...$config],
    ]));

    (new ServiceProvider($app))->register();

    $client = $app->get(Client::class);

    expect(clientHeaders($client))->toHaveKey('anthropic-version', '2023-06-01')
        ->and(clientBaseUri($client))->toBe('https://api.anthropic.com/v1/');
})->with([
    'missing, as in a config published before they existed' => [[]],
    'empty, as an empty .env line gives' => [['api_version' => '', 'base_url' => '']],
    'blank' => [['api_version' => ' ', 'base_url' => ' ']],
    'null' => [['api_version' => null, 'base_url' => null]],
]);

it('adds /v1 to the configured base url, as the official SDKs do', function (string $baseUrl, string $expected) {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'base_url' => $baseUrl,
        ],
    ]));

    (new ServiceProvider($app))->register();

    expect(clientBaseUri($app->get(Client::class)))->toBe($expected);
})->with([
    'a host' => ['https://api.anthropic.com', 'https://api.anthropic.com/v1/'],
    'a host with a trailing slash' => ['https://api.anthropic.com/', 'https://api.anthropic.com/v1/'],
    'a gateway path' => ['https://openrouter.ai/api', 'https://openrouter.ai/api/v1/'],
    'a padded gateway path' => ['  https://openrouter.ai/api/  ', 'https://openrouter.ai/api/v1/'],
    'a local http gateway' => ['http://localhost:4000', 'http://localhost:4000/v1/'],
    'a URL already ending in /v1' => ['https://proxy.example.com/v1/', 'https://proxy.example.com/v1/'],
]);

it('reads the api version and base url from their environment variables', function () {
    // env() reads $_SERVER first, so this wins over a value exported in the shell running the tests
    $values = ['ANTHROPIC_API_VERSION' => '2026-10-21', 'ANTHROPIC_BASE_URL' => 'https://gateway.example.com'];
    $saved = [];

    foreach ($values as $name => $value) {
        $saved[$name] = $_SERVER[$name] ?? null;
        $_SERVER[$name] = $value;
    }

    try {
        $config = require __DIR__.'/../config/anthropic.php';
    } finally {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }

    expect($config)->toMatchArray([
        'api_version' => '2026-10-21',
        'base_url' => 'https://gateway.example.com',
    ]);
});

/**
 * Extracts the base URI a resolved Client sends requests to via reflection.
 */
function clientBaseUri(Client $client): string
{
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);

    return (new ReflectionProperty($transporter, 'baseUri'))->getValue($transporter)->toString();
}

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
