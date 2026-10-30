<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Laravel\AnthropicManager;
use Illuminate\Console\Command;
use Throwable;

class PingCommand extends Command
{
    protected $signature = 'anthropic:ping
        {--connection= : The Anthropic connection, the default one without it}';

    protected $description = 'Checks the key reaches the Anthropic API, with a call that spends no tokens.';

    public function handle(AnthropicManager $manager): int
    {
        $connection = $this->option('connection');
        $started = hrtime(true);

        try {
            // The first page of the models list: a call that spends no tokens.
            $models = $manager->connection(is_string($connection) ? $connection : null)->models()->list(['limit' => 1]);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $milliseconds = intdiv(hrtime(true) - $started, 1_000_000);
        $requestId = $models->meta()->requestId;

        $this->components->info("The Anthropic API answered in {$milliseconds} ms".($requestId === null ? '.' : " (request {$requestId})."));

        return self::SUCCESS;
    }
}
