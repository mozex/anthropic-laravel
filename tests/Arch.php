<?php

test('exceptions')
    ->expect('Anthropic\Laravel\Exceptions')
    ->toUseNothing();

test('facades')
    ->expect('Anthropic\Laravel\Facades\Anthropic')
    ->toOnlyUse([
        'Illuminate\Support\Facades\Facade',
        'Anthropic\Contracts\ResponseContract',
        'Anthropic\Laravel\Testing\AnthropicFake',
        'Anthropic\Responses\StreamResponse',
    ]);

test('service providers')
    ->expect('Anthropic\Laravel\ServiceProvider')
    ->toOnlyUse([
        'GuzzleHttp\Client',
        'Illuminate\Support\ServiceProvider',
        'Anthropic\Laravel',
        'Anthropic',
        'Illuminate\Contracts\Support\DeferrableProvider',

        // helpers...
        'config',
        'config_path',
    ]);
