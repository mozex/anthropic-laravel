<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'die', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('it uses strict types')
    ->expect('Anthropic\Laravel')
    ->toUseStrictTypes();

arch('exceptions')
    ->expect('Anthropic\Laravel\Exceptions')
    ->toUseNothing();

arch('facades')
    ->expect('Anthropic\Laravel\Facades\Anthropic')
    ->toOnlyUse([
        'Illuminate\Foundation\Application',
        'Illuminate\Support\Facades\Facade',
        'Anthropic\Contracts\ClientContract',
        'Anthropic\Contracts\ResponseContract',
        'Anthropic\Contracts\ResponseStreamContract',
        'Anthropic\Laravel\Testing\AnthropicFake',
        'Anthropic\Responses\Completions\StreamResponse',
        'Anthropic\Responses\Messages\StreamResponse',
    ]);

arch('service providers')
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

test('every test file ends in Test.php', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
    $skipped = [];

    foreach ($files as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(__DIR__) + 1));

        if ($file->getExtension() === 'php' && ! str_ends_with($path, 'Test.php') && ! in_array($path, ['Pest.php', 'TestCase.php'], true)) {
            $skipped[] = $path;
        }
    }

    expect($skipped)->toBe([]);
});
