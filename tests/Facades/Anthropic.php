<?php

use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\ServiceProvider;
use Anthropic\Resources\Batches;
use Anthropic\Resources\Completions;
use Anthropic\Resources\Files;
use Anthropic\Resources\Messages;
use Anthropic\Resources\Models;
use Anthropic\Responses\Batches\BatchResponse;
use Anthropic\Responses\Completions\CreateResponse;
use Anthropic\Responses\Files\DeletedFileResponse;
use Anthropic\Responses\Files\FileResponse;
use Anthropic\Responses\Messages\CreateResponse as MessagesCreateResponse;
use Anthropic\Responses\Models\ListResponse as ModelsListResponse;
use Illuminate\Config\Repository;
use PHPUnit\Framework\ExpectationFailedException;

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
