<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Laravel\AnthropicManager;
use Illuminate\Console\Command;
use Throwable;

class CountTokensCommand extends Command
{
    protected $signature = 'anthropic:count-tokens
        {text? : The text to count, as one user message}
        {--model= : The model to count for}
        {--file= : A file whose text to count, in place of the argument}
        {--system= : A system prompt to count with it}
        {--connection= : The Anthropic connection, the default one without it}';

    protected $description = 'Counts the input tokens of a text for a model, with the API\'s free count.';

    public function handle(AnthropicManager $manager): int
    {
        $model = $this->option('model');
        $file = $this->option('file');
        $text = $this->argument('text');

        if (! is_string($model) || $model === '') {
            $this->components->error('Pass the model to count for: --model=claude-sonnet-5-5, say.');

            return self::FAILURE;
        }

        if (is_string($file)) {
            $text = is_file($file) ? file_get_contents($file) : false;

            if ($text === false) {
                $this->components->error("The file [{$file}] can't be read.");

                return self::FAILURE;
            }
        }

        if (! is_string($text) || $text === '') {
            $this->components->error('Pass the text to count, or --file.');

            return self::FAILURE;
        }

        $system = $this->option('system');
        $connection = $this->option('connection');

        try {
            $count = $manager->connection(is_string($connection) ? $connection : null)->messages()->countTokens([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $text]],
                ...(is_string($system) && $system !== '' ? ['system' => $system] : []),
            ]);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Input tokens', number_format($count->inputTokens));

        return self::SUCCESS;
    }
}
