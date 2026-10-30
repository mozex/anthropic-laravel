<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Laravel\ServiceProvider;
use Anthropic\Laravel\Support\View;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

class InstallCommand extends Command
{
    private const LINKS = [
        'Repository' => 'https://github.com/mozex/anthropic-laravel',
        'Anthropic PHP Docs' => 'https://github.com/mozex/anthropic-php#readme',
    ];

    private const FUNDING_LINKS = [
        'Mozex' => 'https://github.com/sponsors/mozex',
    ];

    /**
     * The optional keys go in commented out: an empty line would set them to an
     * empty string, for other packages reading the same names too.
     */
    private const ENV_LINES = [
        'ANTHROPIC_API_KEY' => 'ANTHROPIC_API_KEY=',
        'ANTHROPIC_API_VERSION' => '# ANTHROPIC_API_VERSION=',
        'ANTHROPIC_BASE_URL' => '# ANTHROPIC_BASE_URL=',
        'ANTHROPIC_AUTH_TOKEN' => '# ANTHROPIC_AUTH_TOKEN=',
        'ANTHROPIC_WORKSPACE_ID' => '# ANTHROPIC_WORKSPACE_ID=',
        'ANTHROPIC_CONNECT_TIMEOUT' => '# ANTHROPIC_CONNECT_TIMEOUT=',
        'ANTHROPIC_MAX_RETRIES' => '# ANTHROPIC_MAX_RETRIES=',
        'ANTHROPIC_FALLBACK_MODELS' => '# ANTHROPIC_FALLBACK_MODELS=',
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

        $this->showLinks();

        View::render('components.badge', [
            'type' => 'INFO',
            'content' => 'Open your .env and add your Anthropic API key.',
        ]);

        $this->askToStar();
    }

    private function copyConfig(): void
    {
        if (file_exists(config_path('anthropic.php'))) {
            $missingKeys = $this->keysMissingFromPublishedConfig();

            View::render('components.two-column-detail', [
                'left' => 'config/anthropic.php',
                'right' => $missingKeys === [] ? 'File already exists.' : 'File already exists, without '.implode(', ', $missingKeys).'.',
            ]);

            if ($missingKeys !== []) {
                $this->line(' Copy them into config/anthropic.php from vendor/mozex/anthropic-laravel/config/anthropic.php. Until then, those settings keep their defaults.');
            }

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
        if (! is_writable(base_path($envFile))) {
            View::render('components.two-column-detail', [
                'left' => $envFile,
                'right' => 'File is not writable.',
            ]);

            return;
        }

        $fileContent = file_get_contents(base_path($envFile));

        if ($fileContent === false) {
            return;
        }

        // Each key on its own, so an app installed before a key existed gets it on the next run.
        $missingLines = array_filter(
            self::ENV_LINES,
            static fn (string $key): bool => preg_match('/^[ \t]*(?:#+[ \t]*)?(?:export[ \t]+)?(["\']?)'.$key.'\1[ \t]*=/m', $fileContent) !== 1,
            ARRAY_FILTER_USE_KEY,
        );

        if ($missingLines === []) {
            View::render('components.two-column-detail', [
                'left' => $envFile,
                'right' => 'Variables already exist.',
            ]);

            return;
        }

        file_put_contents(base_path($envFile), PHP_EOL.implode(PHP_EOL, $missingLines).PHP_EOL, FILE_APPEND);

        View::render('components.two-column-detail', [
            'left' => $envFile,
            'right' => implode(', ', array_keys($missingLines)).' added.',
        ]);
    }

    /**
     * The provider merges no defaults, so a key added to the package's config
     * after the app published its copy stays unset until the user adds it.
     *
     * @return list<array-key>
     */
    private function keysMissingFromPublishedConfig(): array
    {
        $published = require config_path('anthropic.php');
        $current = require __DIR__.'/../../config/anthropic.php';

        if (! is_array($published) || ! is_array($current)) {
            return [];
        }

        return array_keys(array_diff_key($current, $published));
    }

    /**
     * A person gets the question, defaulting to yes. A run nobody can answer
     * (--no-interaction, or no terminal on stdin, as with CI and AI agents)
     * takes that default without asking, so it gets a note explaining the
     * browser tab instead.
     */
    private function askToStar(): void
    {
        if (! $this->isInteractive()) {
            $this->line(' If Anthropic for Laravel saves you time, please consider starring it on GitHub: '.self::LINKS['Repository']);

            $this->openInBrowser();

            return;
        }

        if (! $this->confirm(' <options=bold>Would you like to show some love by starring Anthropic for Laravel on GitHub?</>', true)) {
            return;
        }

        if ($this->openInBrowser()) {
            return;
        }

        $this->line(" You'll find Anthropic for Laravel at ".self::LINKS['Repository']);
    }

    /**
     * Laravel's own rule for prompts (stdin must be a terminal, except under
     * unit tests, where the console output is faked), except that
     * --no-interaction always wins, which keeps that path testable.
     */
    private function isInteractive(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        if ($this->laravel->runningUnitTests()) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Best effort: any failure returns false. On Linux the opener runs in the
     * background, because xdg-open without a detected desktop runs the browser
     * in the foreground and would hold the command until the browser closes.
     */
    private function openInBrowser(): bool
    {
        $url = self::LINKS['Repository'];

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['sh', '-c', 'command -v xdg-open > /dev/null && (xdg-open "$1" > /dev/null 2>&1 &)', 'sh', $url],
        };

        try {
            return Process::run($command)->successful();
        } catch (Throwable) {
            return false;
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
