<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Laravel\AnthropicManager;
use Anthropic\Laravel\Connection;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\text;

class ChatCommand extends Command
{
    protected $signature = 'anthropic:chat
        {--model= : The model to chat with}
        {--system= : A system prompt}
        {--max-tokens=1024 : The most tokens one answer may have}
        {--connection= : The Anthropic connection, the default one without it}';

    protected $description = 'Chats with Claude in the terminal, streaming each answer. An empty line ends it.';

    public function handle(AnthropicManager $manager): int
    {
        $model = $this->option('model');
        $maxTokens = filter_var($this->option('max-tokens'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_string($model) || $model === '') {
            $this->components->error('Pass the model to chat with: --model=claude-sonnet-5-5, say.');

            return self::FAILURE;
        }

        if ($maxTokens === false) {
            $this->components->error('--max-tokens takes a whole number of at least 1.');

            return self::FAILURE;
        }

        $connection = $this->option('connection');
        $client = $manager->connection(is_string($connection) ? $connection : null);
        $system = $this->option('system');
        $messages = [];

        while (($prompt = trim(text('You', hint: 'An empty line ends the chat.'))) !== '') {
            $messages[] = ['role' => 'user', 'content' => $prompt];

            try {
                $answer = $this->answer($client, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'messages' => $messages,
                    ...(is_string($system) && $system !== '' ? ['system' => $system] : []),
                ]);
            } catch (Throwable $e) {
                // The turn is dropped, so the next one starts from a conversation the API accepts.
                array_pop($messages);
                $this->components->error($e->getMessage());

                continue;
            }

            // An answer without text (a refusal, say) can't go back as a turn: the API rejects empty content.
            if ($answer === '') {
                array_pop($messages);
                $this->components->warn('Claude answered without any text, so that turn was left out.');

                continue;
            }

            $messages[] = ['role' => 'assistant', 'content' => $answer];
        }

        return self::SUCCESS;
    }

    /**
     * Streams the answer as it comes, through Prompts' `stream()` (0.3.15 and later), to a terminal; prints it once
     * it's whole to a pipe or a file, which would get the stream's frames, and with an older Prompts.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function answer(Connection $client, array $parameters): string
    {
        // Windows has no /dev/tty for the stream's colors, as Laravel's own Prompts fallback knows.
        $stream = function_exists('Laravel\Prompts\stream') && $this->output->isDecorated() && ! windows_os() ? \Laravel\Prompts\stream() : null;
        $answer = '';

        /** @var CreateStreamedResponse $event */
        foreach ($client->messages()->createStreamed($parameters) as $event) {
            if ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta' && is_string($event->delta->text)) {
                $answer .= $event->delta->text;
                $stream?->append($event->delta->text);
            }
        }

        if ($stream === null) {
            $this->line($answer);
        } else {
            $stream->close();
        }

        return $answer;
    }
}
