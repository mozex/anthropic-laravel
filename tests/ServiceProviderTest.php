<?php

declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Anthropic\Laravel\ServiceProvider;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Http\Message\RequestInterface;

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
    $config = configWithEnvironment(['ANTHROPIC_API_VERSION' => '2026-10-21', 'ANTHROPIC_BASE_URL' => 'https://gateway.example.com']);

    expect($config)->toMatchArray([
        'api_version' => '2026-10-21',
        'base_url' => 'https://gateway.example.com',
    ]);
});

it('sends one credential: the api key when one is set, the auth token otherwise', function (mixed $apiKey, bool $sendsKey) {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => $apiKey,
            // composed: the authentication docs show no token value
            'auth_token' => ' sk-ant-oat01-token ',
        ],
    ]));

    (new ServiceProvider($app))->register();

    $headers = clientHeaders($app->get(Client::class));

    expect(array_key_exists('x-api-key', $headers))->toBe($sendsKey)
        ->and(array_key_exists('Authorization', $headers))->toBe(! $sendsKey);

    if (! $sendsKey) {
        expect($headers['Authorization'])->toBe('Bearer sk-ant-oat01-token');
    }
})->with([
    'no key' => [null, false],
    'an empty key line' => ['', false],
    'a blank key' => ['  ', false],
    'a key' => ['test', true],
]);

it('still requires an api key when the auth token is blank', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => ['auth_token' => '  '],
    ]));

    (new ServiceProvider($app))->register();

    $app->get(Client::class);
})->throws(ApiKeyIsMissing::class);

it('sends the workspace id and the extra headers', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ',
            'beta' => ['files-api-2025-04-14'],
            // composed: no docs example of gateway headers
            'headers' => ['X-Tenant' => 'acme', 'X-Org' => 42, 'X-Bad' => ['not', 'a', 'string'], 'anthropic-beta' => 'compact-2026-09-04'],
        ],
    ]));

    (new ServiceProvider($app))->register();

    $headers = clientHeaders($app->get(Client::class));

    expect($headers['anthropic-workspace-id'])->toBe('wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ')
        ->and($headers['X-Tenant'])->toBe('acme')
        ->and($headers['X-Org'])->toBe('42')
        ->and($headers)->not->toHaveKey('X-Bad')
        // the headers key is applied last, so it wins over the beta key
        ->and($headers['anthropic-beta'])->toBe('compact-2026-09-04');
});

it('sets the connect timeout on the guzzle client', function (mixed $connectTimeout, ?float $expected) {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
            'request_timeout' => 60,
            'connect_timeout' => $connectTimeout,
        ],
    ]));

    (new ServiceProvider($app))->register();

    $client = $app->get(Client::class);
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);
    $guzzle = (new ReflectionProperty($transporter, 'client'))->getValue($transporter);

    expect($guzzle->getConfig('timeout'))->toBe(60)
        ->and($guzzle->getConfig('connect_timeout'))->toBe($expected);
})->with([
    'a number from .env' => ['5', 5.0],
    'empty' => [null, null],
    'not a number' => ['soon', null],
    'not positive' => ['-1', null],
]);

it('reads the new keys from their environment variables', function () {
    $config = configWithEnvironment([
        'ANTHROPIC_AUTH_TOKEN' => 'sk-ant-oat01-token',
        'ANTHROPIC_WORKSPACE_ID' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ',
        'ANTHROPIC_CONNECT_TIMEOUT' => '5',
        'ANTHROPIC_MAX_RETRIES' => '2',
    ]);

    expect($config)->toMatchArray([
        'auth_token' => 'sk-ant-oat01-token',
        'workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ',
        'connect_timeout' => '5',
        'max_retries' => '2',
        'http' => ['middleware' => []],
        'headers' => [],
    ]);
});

it('retries as many times as the config says', function (mixed $maxRetries, int $expected) {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => ['api_key' => 'test', 'max_retries' => $maxRetries],
    ]));

    (new ServiceProvider($app))->register();

    $client = $app->get(Client::class);
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);

    expect((new ReflectionProperty($transporter, 'maxRetries'))->getValue($transporter))->toBe($expected);
})->with([
    'a number from .env' => ['2', 2],
    'an int' => [2, 2],
    'the default' => [0, 0],
    'empty' => [null, 0],
    'not a number' => ['many', 0],
    'not positive' => ['-1', 0],
]);

it('pushes the configured guzzle middleware onto the stack', function (array|string $configured) {
    $app = app();
    $middleware = new RecordingGuzzleMiddleware;
    $app->instance(RecordingGuzzleMiddleware::class, $middleware);

    $app->bind('config', fn () => new Repository([
        'anthropic' => ['api_key' => 'test', 'http' => ['middleware' => $configured]],
    ]));

    (new ServiceProvider($app))->register();

    $guzzle = clientGuzzle($app->get(Client::class));
    $stack = $guzzle->getConfig('handler');
    $stack->setHandler(new MockHandler([new Response(204)]));

    $guzzle->request('GET', 'https://api.anthropic.com/v1/models');

    expect($middleware->requests)->toHaveCount(1)
        // Guzzle's own middleware stay on the stack
        ->and((string) $stack)->toContain('http_errors')->toContain('prepare_body');
})->with([
    'a list' => [[RecordingGuzzleMiddleware::class]],
    'one class name' => [RecordingGuzzleMiddleware::class],
]);

it('takes a callable as well as a class name', function () {
    $app = app();
    $middleware = new RecordingGuzzleMiddleware;

    $app->bind('config', fn () => new Repository([
        'anthropic' => ['api_key' => 'test', 'http' => ['middleware' => [$middleware(...)]]],
    ]));

    (new ServiceProvider($app))->register();

    $guzzle = clientGuzzle($app->get(Client::class));
    $guzzle->getConfig('handler')->setHandler(new MockHandler([new Response(204)]));

    $guzzle->request('GET', 'https://api.anthropic.com/v1/models');

    expect($middleware->requests)->toHaveCount(1);
});

it('builds the client when no middleware is configured', function (array $config) {
    $app = app();

    $app->bind('config', fn () => new Repository(['anthropic' => ['api_key' => 'test', ...$config]]));

    (new ServiceProvider($app))->register();

    expect(clientGuzzle($app->get(Client::class))->getConfig('handler'))->toBeInstanceOf(HandlerStack::class);
})->with([
    'no http key' => [[]],
    'http is not an array' => [['http' => 'guzzle']],
    'middleware is empty' => [['http' => ['middleware' => null]]],
]);

it('throws for a middleware entry that is not one', function (mixed $entry, string $exception) {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => ['api_key' => 'test', 'http' => ['middleware' => [$entry]]],
    ]));

    (new ServiceProvider($app))->register();

    expect(fn () => $app->get(Client::class))->toThrow($exception);
})->with([
    'a class that is not invokable' => [stdClass::class, InvalidArgumentException::class],
    'a number' => [5, InvalidArgumentException::class],
    'a class that does not exist' => ['App\Http\Anthropic\Missing', BindingResolutionException::class],
]);

class RecordingGuzzleMiddleware
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $this->requests[] = $request;

            return $handler($request, $options);
        };
    }
}

/**
 * Extracts the Guzzle client a resolved Client sends requests with via reflection.
 */
function clientGuzzle(Client $client): GuzzleHttp\Client
{
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);

    return (new ReflectionProperty($transporter, 'client'))->getValue($transporter);
}

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

/**
 * Loads the package's config file with the given environment variables set, then restores them.
 *
 * @param  array<string, string>  $values
 * @return array<string, mixed>
 */
function configWithEnvironment(array $values): array
{
    // env() reads $_SERVER first, so this wins over a value exported in the shell running the tests
    $saved = [];

    foreach ($values as $name => $value) {
        $saved[$name] = $_SERVER[$name] ?? null;
        $_SERVER[$name] = $value;
    }

    try {
        return require __DIR__.'/../config/anthropic.php';
    } finally {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }
}
