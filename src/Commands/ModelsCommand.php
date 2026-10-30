<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Laravel\AnthropicManager;
use Anthropic\Responses\Models\RetrieveResponse;
use Illuminate\Console\Command;
use Throwable;

class ModelsCommand extends Command
{
    protected $signature = 'anthropic:models
        {--connection= : The Anthropic connection, the default one without it}';

    protected $description = 'Lists the models the key can use, with their input limit and lifecycle.';

    public function handle(AnthropicManager $manager): int
    {
        $connection = $this->option('connection');

        try {
            $models = iterator_to_array($manager->connection(is_string($connection) ? $connection : null)->models()->iterate(), false);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Name', 'Input tokens', 'Lifecycle', 'Retires'],
            array_map(fn (RetrieveResponse $model): array => [
                $model->id,
                $model->displayName,
                $model->maxInputTokens === null ? '-' : number_format($model->maxInputTokens),
                $model->lifecycle ?? '-',
                $model->retiresAt ?? '-',
            ], $models),
        );

        return self::SUCCESS;
    }
}
