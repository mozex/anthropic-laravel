<?php

use Illuminate\Config\Repository;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\ServiceProvider;
use Anthropic\Resources\Completions;
use Anthropic\Responses\Completions\CreateResponse;
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

    $completions = Anthropic::completions();

    expect($completions)->toBeInstanceOf(Completions::class);
});

test('fake returns the given response', function () {
    Anthropic::fake([
        CreateResponse::fake([
            'choices' => [
                [
                    'text' => 'awesome!',
                ],
            ],
        ]),
    ]);

    $completion = Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    expect($completion['choices'][0]['text'])->toBe('awesome!');
});

test('fake throws an exception if there is no more given response', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);
})->expectExceptionMessage('No fake responses left');

test('append more fake responses', function () {
    Anthropic::fake([
        CreateResponse::fake([
            'id' => 'cmpl-1',
        ]),
    ]);

    Anthropic::addResponses([
        CreateResponse::fake([
            'id' => 'cmpl-2',
        ]),
    ]);

    $completion = Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    expect($completion)
        ->id->toBe('cmpl-1');

    $completion = Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    expect($completion)
        ->id->toBe('cmpl-2');
});

test('fake can assert a request was sent', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertSent(Completions::class, function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'gpt-3.5-turbo-instruct' &&
            $parameters['prompt'] === 'PHP is ';
    });
});

test('fake throws an exception if a request was not sent', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::assertSent(Completions::class, function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'gpt-3.5-turbo-instruct' &&
            $parameters['prompt'] === 'PHP is ';
    });
})->expectException(ExpectationFailedException::class);

test('fake can assert a request was sent on the resource', function () {
    Anthropic::fake([
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::completions()->assertSent(function (string $method, array $parameters): bool {
        return $method === 'create' &&
            $parameters['model'] === 'gpt-3.5-turbo-instruct' &&
            $parameters['prompt'] === 'PHP is ';
    });
});

test('fake can assert a request was sent n times', function () {
    Anthropic::fake([
        CreateResponse::fake(),
        CreateResponse::fake(),
    ]);

    Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::completions()->create([
        'model' => 'gpt-3.5-turbo-instruct',
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
        'model' => 'gpt-3.5-turbo-instruct',
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
        'model' => 'gpt-3.5-turbo-instruct',
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
        'model' => 'gpt-3.5-turbo-instruct',
        'prompt' => 'PHP is ',
    ]);

    Anthropic::assertNothingSent();
})->expectException(ExpectationFailedException::class);
