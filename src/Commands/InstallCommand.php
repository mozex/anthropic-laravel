<?php

namespace Anthropic\Laravel\Commands;

use Illuminate\Console\Command;
use Anthropic\Laravel\ServiceProvider;
use Anthropic\Laravel\Support\View;

class InstallCommand extends Command
{
    private const LINKS = [
        'Repository' => 'https://github.com/anthropic-php/laravel',
        'Anthropic PHP Docs' => 'https://github.com/anthropic-php/client#readme',
        'Join us on Telegram' => 'https://t.me/+66GDs6UM6RcxY2U8',
    ];

    private const FUNDING_LINKS = [
        'Sponsor Sandro' => 'https://github.com/sponsors/gehrisandro',
        'Sponsor Nuno' => 'https://github.com/sponsors/nunomaduro',
    ];

    protected $signature = 'anthropic:install';

    protected $description = 'Prepares the Anthropic client for use.';

    public function handle(): void
    {
        View::renderUsing($this->output);

        View::render('components.badge', [
            'type' => 'INFO',
            'content' => 'Installing Anthropic for Laravel.',
        ]);

        $this->copyConfig();

        View::render('components.new-line');

        $this->addEnvKeys('.env');
        $this->addEnvKeys('.env.example');

        View::render('components.new-line');

        $wantsToSupport = $this->askToStarRepository();

        $this->showLinks();

        View::render('components.badge', [
            'type' => 'INFO',
            'content' => 'Open your .env and add your Anthropic API key and organization id.',
        ]);

        if ($wantsToSupport) {
            $this->openRepositoryInBrowser();
        }
    }

    private function copyConfig(): void
    {
        if (file_exists(config_path('anthropic.php'))) {
            View::render('components.two-column-detail', [
                'left' => 'config/anthropic.php',
                'right' => 'File already exists.',
            ]);

            return;
        }

        View::render('components.two-column-detail', [
            'left' => 'config/anthropic.php',
            'right' => 'File created.',
        ]);

        $this->callSilent('vendor:publish', [
            '--provider' => ServiceProvider::class,
        ]);
    }

    private function addEnvKeys(string $envFile): void
    {
        $fileContent = file_get_contents(base_path($envFile));

        if ($fileContent === false) {
            return;
        }

        if (str_contains($fileContent, 'OPENAI_API_KEY')) {
            View::render('components.two-column-detail', [
                'left' => $envFile,
                'right' => 'Variables already exists.',
            ]);

            return;
        }

        file_put_contents(base_path($envFile), PHP_EOL.'OPENAI_API_KEY='.PHP_EOL.'OPENAI_ORGANIZATION='.PHP_EOL, FILE_APPEND);

        View::render('components.two-column-detail', [
            'left' => $envFile,
            'right' => 'OPENAI_API_KEY and OPENAI_ORGANIZATION variables added.',
        ]);
    }

    private function askToStarRepository(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm(' <options=bold>Wanna show Anthropic for Laravel some love by starring it on GitHub?</>', false);
    }

    private function openRepositoryInBrowser(): void
    {
        if (PHP_OS_FAMILY == 'Darwin') {
            exec('open https://github.com/anthropic-php/laravel');
        }
        if (PHP_OS_FAMILY == 'Windows') {
            exec('start https://github.com/anthropic-php/laravel');
        }
        if (PHP_OS_FAMILY == 'Linux') {
            exec('xdg-open https://github.com/anthropic-php/laravel');
        }
    }

    private function showLinks(): void
    {
        $links = [
            ...self::LINKS,
            ...rand(0, 1) ? self::FUNDING_LINKS : array_reverse(self::FUNDING_LINKS, true),
        ];

        foreach ($links as $message => $link) {
            View::render('components.two-column-detail', [
                'left' => $message,
                'right' => $link,
            ]);
        }
    }
}
