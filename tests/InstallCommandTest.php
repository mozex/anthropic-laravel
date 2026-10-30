<?php

declare(strict_types=1);

use Anthropic\Laravel\ServiceProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

const STAR_QUESTION = ' <options=bold>Would you like to show some love by starring Anthropic for Laravel on GitHub?</>';

beforeEach(function () {
    Process::fake();

    // without lines a killed run may have left in the skeleton's file
    $this->envExample = preg_replace('/^\s*[^\r\n]*ANTHROPIC_[^\r\n]*\R?/m', '', (string) file_get_contents(base_path('.env.example')));
    file_put_contents(base_path('.env.example'), $this->envExample);

    @unlink(config_path('anthropic.php'));
});

afterEach(function () {
    file_put_contents(base_path('.env.example'), $this->envExample);

    @unlink(config_path('anthropic.php'));
});

function assertOpenedRepository(): void
{
    Process::assertRan(
        fn (PendingProcess $process): bool => in_array('https://github.com/mozex/anthropic-laravel', (array) $process->command, true)
    );
}

it('publishes the config and adds the api key variable', function () {
    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(config_path('anthropic.php'))->toBeFile()
        ->and(file_get_contents(base_path('.env.example')))->toContain('ANTHROPIC_API_KEY=');
});

it('adds the optional variables commented out', function () {
    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('ANTHROPIC_API_KEY, ANTHROPIC_API_VERSION, ANTHROPIC_BASE_URL, ANTHROPIC_AUTH_TOKEN, ANTHROPIC_WORKSPACE_ID, ANTHROPIC_CONNECT_TIMEOUT, ANTHROPIC_MAX_RETRIES, ANTHROPIC_FALLBACK_MODELS, ANTHROPIC_HTTP_LARAVEL_CLIENT, ANTHROPIC_HTTP_LARAVEL_EVENTS, ANTHROPIC_HTTP_TELESCOPE, ANTHROPIC_HTTP_NIGHTWATCH, ANTHROPIC_CONTEXT added.')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.env.example')))
        ->toEndWith(PHP_EOL.'ANTHROPIC_API_KEY='.PHP_EOL.'# ANTHROPIC_API_VERSION='.PHP_EOL.'# ANTHROPIC_BASE_URL='.PHP_EOL.'# ANTHROPIC_AUTH_TOKEN='.PHP_EOL.'# ANTHROPIC_WORKSPACE_ID='.PHP_EOL.'# ANTHROPIC_CONNECT_TIMEOUT='.PHP_EOL.'# ANTHROPIC_MAX_RETRIES='.PHP_EOL.'# ANTHROPIC_FALLBACK_MODELS='.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_CLIENT='.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_EVENTS='.PHP_EOL.'# ANTHROPIC_HTTP_TELESCOPE='.PHP_EOL.'# ANTHROPIC_HTTP_NIGHTWATCH='.PHP_EOL.'# ANTHROPIC_CONTEXT='.PHP_EOL);
});

it('adds only the variables an env file lacks', function () {
    file_put_contents(base_path('.env.example'), $this->envExample.PHP_EOL.'ANTHROPIC_API_KEY=sk-ant-test'.PHP_EOL.'ANTHROPIC_API_VERSION=2026-10-21'.PHP_EOL);

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('ANTHROPIC_BASE_URL, ANTHROPIC_AUTH_TOKEN, ANTHROPIC_WORKSPACE_ID, ANTHROPIC_CONNECT_TIMEOUT, ANTHROPIC_MAX_RETRIES, ANTHROPIC_FALLBACK_MODELS, ANTHROPIC_HTTP_LARAVEL_CLIENT, ANTHROPIC_HTTP_LARAVEL_EVENTS, ANTHROPIC_HTTP_TELESCOPE, ANTHROPIC_HTTP_NIGHTWATCH, ANTHROPIC_CONTEXT added.')
        ->assertSuccessful();

    $envExample = (string) file_get_contents(base_path('.env.example'));

    expect(substr_count($envExample, 'ANTHROPIC_API_KEY'))->toBe(1)
        ->and(substr_count($envExample, 'ANTHROPIC_API_VERSION'))->toBe(1)
        ->and($envExample)->toContain('ANTHROPIC_API_KEY=sk-ant-test')
        ->and($envExample)->toEndWith(PHP_EOL.'# ANTHROPIC_BASE_URL='.PHP_EOL.'# ANTHROPIC_AUTH_TOKEN='.PHP_EOL.'# ANTHROPIC_WORKSPACE_ID='.PHP_EOL.'# ANTHROPIC_CONNECT_TIMEOUT='.PHP_EOL.'# ANTHROPIC_MAX_RETRIES='.PHP_EOL.'# ANTHROPIC_FALLBACK_MODELS='.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_CLIENT='.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_EVENTS='.PHP_EOL.'# ANTHROPIC_HTTP_TELESCOPE='.PHP_EOL.'# ANTHROPIC_HTTP_NIGHTWATCH='.PHP_EOL.'# ANTHROPIC_CONTEXT='.PHP_EOL);
});

it('adds a key whose name only appears inside another name or a value', function () {
    file_put_contents(base_path('.env.example'), $this->envExample.PHP_EOL.'MY_ANTHROPIC_API_KEY=x'.PHP_EOL.'PROXY=${ANTHROPIC_BASE_URL}'.PHP_EOL);

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('ANTHROPIC_API_KEY, ANTHROPIC_API_VERSION, ANTHROPIC_BASE_URL, ANTHROPIC_AUTH_TOKEN, ANTHROPIC_WORKSPACE_ID, ANTHROPIC_CONNECT_TIMEOUT, ANTHROPIC_MAX_RETRIES, ANTHROPIC_FALLBACK_MODELS, ANTHROPIC_HTTP_LARAVEL_CLIENT, ANTHROPIC_HTTP_LARAVEL_EVENTS, ANTHROPIC_HTTP_TELESCOPE, ANTHROPIC_HTTP_NIGHTWATCH, ANTHROPIC_CONTEXT added.')
        ->assertSuccessful();
});

it('counts quoted and commented-out export lines as present', function () {
    $quoted = $this->envExample.PHP_EOL.'"ANTHROPIC_API_KEY"=sk-ant-test'.PHP_EOL.'# export ANTHROPIC_API_VERSION='.PHP_EOL.'## ANTHROPIC_BASE_URL='.PHP_EOL.'ANTHROPIC_AUTH_TOKEN=x'.PHP_EOL.'#ANTHROPIC_WORKSPACE_ID='.PHP_EOL.'"ANTHROPIC_CONNECT_TIMEOUT"=5'.PHP_EOL.'ANTHROPIC_MAX_RETRIES=2'.PHP_EOL.'ANTHROPIC_FALLBACK_MODELS=claude-opus-4-8'.PHP_EOL.'ANTHROPIC_HTTP_LARAVEL_CLIENT=true'.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_EVENTS='.PHP_EOL.'ANTHROPIC_HTTP_TELESCOPE=true'.PHP_EOL.'# export ANTHROPIC_HTTP_NIGHTWATCH='.PHP_EOL.'"ANTHROPIC_CONTEXT"=true'.PHP_EOL;

    file_put_contents(base_path('.env.example'), $quoted);

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('Variables already exist.')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.env.example')))->toBe($quoted);
});

it('names the keys a config published by an older release lacks', function () {
    file_put_contents(config_path('anthropic.php'), "<?php\n\nreturn ['api_key' => null, 'request_timeout' => 30, 'beta' => []];\n");

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('File already exists, without auth_token, workspace_id, connect_timeout, max_retries, middleware, fallback_models, http, context, headers, connections, api_version, base_url.')
        ->expectsOutputToContain('Copy them into config/anthropic.php from vendor/mozex/anthropic-laravel/config/anthropic.php.')
        ->assertSuccessful();
});

it('names the http switches a published http array lacks', function () {
    file_put_contents(config_path('anthropic.php'), "<?php\n\nreturn array_replace(require '".str_replace('\\', '/', realpath(__DIR__.'/../config/anthropic.php'))."', ['http' => ['middleware' => [], 'laravel_client' => false, 'laravel_events' => false]]);\n");

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('File already exists, without http.telescope, http.nightwatch.')
        ->assertSuccessful();
});

it('says nothing more when the published config has every key', function () {
    $this->artisan('vendor:publish', ['--tag' => 'anthropic-config'])->assertSuccessful();

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('File already exists.')
        ->doesntExpectOutputToContain('Copy them into')
        ->assertSuccessful();
});

it('leaves an env file that has every variable as it is', function () {
    $complete = $this->envExample.PHP_EOL.'ANTHROPIC_API_KEY='.PHP_EOL.'# ANTHROPIC_API_VERSION='.PHP_EOL.'export ANTHROPIC_BASE_URL=https://gateway.example.com'.PHP_EOL.'# ANTHROPIC_AUTH_TOKEN='.PHP_EOL.'# ANTHROPIC_WORKSPACE_ID='.PHP_EOL.'# ANTHROPIC_CONNECT_TIMEOUT='.PHP_EOL.'# ANTHROPIC_MAX_RETRIES='.PHP_EOL.'# ANTHROPIC_FALLBACK_MODELS='.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_CLIENT='.PHP_EOL.'# ANTHROPIC_HTTP_LARAVEL_EVENTS='.PHP_EOL.'# ANTHROPIC_HTTP_TELESCOPE='.PHP_EOL.'# ANTHROPIC_HTTP_NIGHTWATCH='.PHP_EOL.'# ANTHROPIC_CONTEXT='.PHP_EOL;

    file_put_contents(base_path('.env.example'), $complete);

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->expectsOutputToContain('Variables already exist.')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.env.example')))->toBe($complete);
});

it('opens the repository when the user agrees to star it', function () {
    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->doesntExpectOutputToContain('please consider starring')
        ->doesntExpectOutputToContain("You'll find")
        ->assertSuccessful();

    assertOpenedRepository();
});

it('opens nothing when the user declines', function () {
    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('prints the repository link when the browser cannot be opened', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find Anthropic for Laravel at https://github.com/mozex/anthropic-laravel")
        ->assertSuccessful();
});

it('prints the repository link when opening the browser throws', function () {
    Process::fake(fn () => throw new RuntimeException('No opener.'));

    $this->artisan('anthropic:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find Anthropic for Laravel at https://github.com/mozex/anthropic-laravel")
        ->assertSuccessful();
});

it('opens the repository with a note instead of asking when nobody can answer', function () {
    $this->artisan('anthropic:install', ['--no-interaction' => true])
        ->expectsOutputToContain('If Anthropic for Laravel saves you time, please consider starring it on GitHub: https://github.com/mozex/anthropic-laravel')
        ->assertSuccessful();

    assertOpenedRepository();

    expect(config_path('anthropic.php'))->toBeFile();
});

it('publishes the config with the documented vendor:publish command', function () {
    $this->artisan('vendor:publish', ['--tag' => 'anthropic-config'])
        ->assertSuccessful();

    expect(config_path('anthropic.php'))->toBeFile();
});

it('still publishes the config by provider', function () {
    $this->artisan('vendor:publish', ['--provider' => ServiceProvider::class])
        ->assertSuccessful();

    expect(config_path('anthropic.php'))->toBeFile();
});
