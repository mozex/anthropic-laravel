<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

const STAR_QUESTION = ' <options=bold>Would you like to show some love by starring Anthropic for Laravel on GitHub?</>';

beforeEach(function () {
    Process::fake();

    $this->envExample = file_get_contents(base_path('.env.example'));

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
