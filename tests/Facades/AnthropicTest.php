<?php

declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Exceptions\ErrorException;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\ServiceProvider;
use Anthropic\Resources\Batches;
use Anthropic\Resources\Completions;
use Anthropic\Resources\Files;
use Anthropic\Resources\Messages;
use Anthropic\Resources\Models;
use Anthropic\Resources\Raw;
use Anthropic\Responses\Batches\BatchResponse;
use Anthropic\Responses\Completions\CreateResponse;
use Anthropic\Responses\Files\DeletedFileResponse;
use Anthropic\Responses\Files\FileResponse;
use Anthropic\Responses\Messages\CreateResponse as MessagesCreateResponse;
use Anthropic\Responses\Models\ListResponse as ModelsListResponse;
use Anthropic\Responses\Raw\RawResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;

class ClaudeGreeter
{
    public function __construct(public ClientContract $client) {}
}

it('resolves resources', function () {
    $app = app();

    $app->bind('config', fn () => new Repository([
        'anthropic' => [
            'api_key' => 'test',
        ],
    ]));

    (new ServiceProvider($app))->register();

    Anthropic::setFacadeApplication($app);

    expect(Anthropic::completions())->toBeInstanceOf(Completions::class)
        ->and(Anthropic::messages())->toBeInstanceOf(Messages::class)
        ->and(Anthropic::models())->toBeInstanceOf(Models::class)
        ->and(Anthropic::batches())->toBeInstanceOf(Batches::class)
        ->and(Anthropic::files())->toBeInstanceOf(Files::class);
});

test('fake returns the given response', function () {
    Anthropic::fake([
        CreateResponse::fake([
            'completion' => 'awesome!',
        ]),
    ]);

    $completion = Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    expect($completion['completion'])->toBe('awesome!');
});

test('fake throws an exception if there is no more given response', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.0',
        'prompt' => 'PHP is ',
    ]);
})->expectExceptionMessage('No fake responses left');

test('append more fake responses', function () {
    Anthropic::fake([
        CreateResponse::fake([
            'id' => 'compl_1',
        ]),
    ]);

    Anthropic::addResponses([
        CreateResponse::fake([
            'id' => 'compl_2',
        ]),
    ]);

    $completion = Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    expect($completion)
        ->id->toBe('compl_1');

    $completion = Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    expect($completion)
        ->id->toBe('compl_2');
});

test('fake can assert a request was sent', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertSent(Completions::class, function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'claude-2.1' &&
            $parameters['prompt'] === 'PHP is ';
    });
});

test('fake throws an exception if a request was not sent', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::assertSent(Completions::class, function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'claude-2.1' &&
            $parameters['prompt'] === 'PHP is ';
    });
})->expectException(ExpectationFailedException::class);

test('fake can assert a request was sent on the resource', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::completions()->assertSent(function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'claude-2.1' &&
            $parameters['prompt'] === 'PHP is ';
    });
});

test('fake can assert a request was sent n times', function () {
    Anthropic::fake([
        CreateResponse::fake(),
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertSent(Completions::class, 2);
});

test('fake throws an exception if a request was not sent n times', function () {
    Anthropic::fake([
        CreateResponse::fake(),
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertSent(Completions::class, 2);
})->expectException(ExpectationFailedException::class);

test('fake can assert a request was not sent', function () {
    Anthropic::fake();

    Anthropic::assertNotSent(Completions::class);
});

test('fake throws an exception if a unexpected request was sent', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertNotSent(Completions::class);
})->expectException(ExpectationFailedException::class);

test('fake can assert a request was not sent on the resource', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->assertNotSent();
});

test('fake can assert no request was sent', function () {
    Anthropic::fake();

    Anthropic::assertNothingSent();
});

test('fake throws an exception if any request was sent when non was expected', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'claude-2.1',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertNothingSent();
})->expectException(ExpectationFailedException::class);

// Messages

test('fake messages returns the given response', function () {
    Anthropic::fake([
        MessagesCreateResponse::fake([
            'id' => 'msg_test',
        ]),
    ]);

    $result = Anthropic::messages()->create([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => 'Hello!'],
        ],
    ]);

    expect($result)->id->toBe('msg_test');
});

test('fake messages can assert a request was sent', function () {
    Anthropic::fake([
        MessagesCreateResponse::fake(),
    ]);

    Anthropic::messages()->create([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => 'Hello!'],
        ],
    ]);

    Anthropic::assertSent(Messages::class, function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'claude-sonnet-4-6';
    });
});

// Models

test('fake models returns the given response', function () {
    Anthropic::fake([
        ModelsListResponse::fake(),
    ]);

    $result = Anthropic::models()->list();

    expect($result)->toBeInstanceOf(ModelsListResponse::class);
});

test('fake models can assert a request was sent', function () {
    Anthropic::fake([
        ModelsListResponse::fake(),
    ]);

    Anthropic::models()->list(['limit' => 10]);

    Anthropic::assertSent(Models::class, function (string $method, array $parameters): bool {
        return $method === 'list' &&
            $parameters === ['limit' => 10];
    });
});

// Batches

test('fake batches returns the given response', function () {
    Anthropic::fake([
        BatchResponse::fake([
            'id' => 'msgbatch_test',
        ]),
    ]);

    $result = Anthropic::batches()->create([
        'requests' => [],
    ]);

    expect($result)->id->toBe('msgbatch_test');
});

test('fake batches can assert a request was sent', function () {
    Anthropic::fake([
        BatchResponse::fake(),
    ]);

    Anthropic::batches()->retrieve('msgbatch_123');

    Anthropic::assertSent(Batches::class, function (string $method, string $id): bool {
        return $method === 'retrieve' &&
            $id === 'msgbatch_123';
    });
});

// Files

test('fake files returns the given response', function () {
    Anthropic::fake([
        FileResponse::fake([
            'id' => 'file_test',
        ]),
    ]);

    $result = Anthropic::files()->upload([
        'file' => 'bytes',
    ]);

    expect($result)->id->toBe('file_test');
});

test('fake files can assert a request was sent', function () {
    Anthropic::fake([
        DeletedFileResponse::fake(),
    ]);

    Anthropic::files()->delete('file_011CNha8iCJcU1wXNR6q4V8w');

    Anthropic::assertSent(Files::class, function (string $method, string $id): bool {
        return $method === 'delete' &&
            $id === 'file_011CNha8iCJcU1wXNR6q4V8w';
    });
});

// Container

test('fake reaches code that type-hints the client contract, before and after the deferred providers load', function () {
    $container = Container::getInstance();
    $facadeApplication = Facade::getFacadeApplication();

    try {
        // A fresh app that defers the provider, as a real one does. No API key: the real client can't be built.
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([]));
        $app->addDeferredServices([
            Client::class => ServiceProvider::class,
            ClientContract::class => ServiceProvider::class,
            'anthropic' => ServiceProvider::class,
        ]);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $fake = Anthropic::fake();

        expect($app->make(ClaudeGreeter::class)->client)->toBe($fake);

        $app->loadDeferredProviders();

        expect($app->make(ClaudeGreeter::class)->client)->toBe($fake)
            ->and(Anthropic::getFacadeRoot())->toBe($fake);
    } finally {
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($facadeApplication);
    }
});

test('fake accepts exceptions and streams as responses', function () {
    Anthropic::fake([
        new ErrorException(['type' => 'overloaded_error', 'message' => 'Overloaded'], 529),
        Utils::streamFor('file bytes'),
    ]);

    expect(fn () => Anthropic::messages()->create(['model' => 'claude-haiku-4-5']))
        ->toThrow(ErrorException::class, 'Overloaded')
        ->and(Anthropic::files()->download('file_011CNha8iCJcU1wXNR6q4V8w'))->toBe('file bytes');
});

test('a raw request through the facade fake returns the queued response', function () {
    $fake = Anthropic::fake([
        RawResponse::fake(['data' => [], 'next_page' => null]),
    ]);

    expect(Anthropic::request('GET', 'skills')->data)->toBe(['data' => [], 'next_page' => null]);

    $fake->assertSent(Raw::class, fn (string $method, string $httpMethod, string $path): bool => $httpMethod === 'GET' && $path === 'skills');
});

test('iterate() through the facade fake walks the queued pages', function () {
    $fake = Anthropic::fake([
        // composed: the fixture with has_more and a cursor set
        ModelsListResponse::fake(['has_more' => true, 'last_id' => 'claude-haiku-4-5']),
        ModelsListResponse::fake(),
    ]);

    expect(iterator_to_array(Anthropic::models()->iterate()))->toHaveCount(2);

    $fake->assertSent(Models::class, 2);
    $fake->assertSent(Models::class, fn (string $method, array $parameters): bool => ($parameters['after_id'] ?? null) === 'claude-haiku-4-5');
});

test('a withOptions client from the facade fake shares its queue and records its options', function () {
    $fake = Anthropic::fake([
        MessagesCreateResponse::fake(),
    ]);

    Anthropic::withOptions(['workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ'])->messages()->create([
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 10,
        'messages' => [['role' => 'user', 'content' => 'Hello']],
    ]);

    $fake->assertSent(Messages::class, 1);
    $fake->assertSentWithOptions(Messages::class, ['workspace_id' => 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ']);
});

test('withOptions on the facade returns a client with the options, leaving the bound one unchanged', function () {
    $app = app();
    $app->bind('config', fn () => new Repository([
        // composed: workspace IDs shortened
        'anthropic' => ['api_key' => 'test', 'workspace_id' => 'wrkspc_a'],
    ]));
    (new ServiceProvider($app))->register();
    Facade::clearResolvedInstances();
    Anthropic::setFacadeApplication($app);

    $tenant = Anthropic::withOptions(['workspace_id' => 'wrkspc_b', 'betas' => ['compact-2026-09-04']]);

    $headersOf = function (object $client): array {
        $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);

        return (new ReflectionProperty($transporter, 'headers'))->getValue($transporter)->toArray();
    };

    expect($tenant)->toBeInstanceOf(Client::class)
        ->and($headersOf($tenant)['anthropic-workspace-id'])->toBe('wrkspc_b')
        ->and($headersOf($tenant)['anthropic-beta'])->toBe('compact-2026-09-04')
        ->and($headersOf($app->get(ClientContract::class))['anthropic-workspace-id'])->toBe('wrkspc_a');
});
