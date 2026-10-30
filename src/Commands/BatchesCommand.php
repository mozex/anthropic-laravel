<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Exceptions\ErrorException;
use Anthropic\Exceptions\TransporterException;
use Anthropic\Laravel\AnthropicManager;
use Anthropic\Responses\Batches\BatchResponse;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BatchesCommand extends Command
{
    protected $signature = 'anthropic:batches
        {id? : A batch ID, to show that batch}
        {--connection= : The Anthropic connection, the default one without it}
        {--limit=20 : How many of the latest batches to list}';

    protected $description = 'Lists the latest Message Batches, or shows one, with its request counts.';

    public function handle(AnthropicManager $manager): int
    {
        $connection = $this->option('connection');
        $id = $this->argument('id');

        try {
            $batches = $manager->connection(is_string($connection) ? $connection : null)->batches();

            if (is_string($id)) {
                $this->show($batches->retrieve($id));

                return self::SUCCESS;
            }

            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($limit === false) {
                $this->components->error('--limit takes a whole number of at least 1.');

                return self::FAILURE;
            }

            $list = $batches->list(['limit' => $limit]);
        } catch (ErrorException|TransporterException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($list->data === []) {
            $this->components->info('No batches yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Status', 'Processing', 'Succeeded', 'Errored', 'Canceled', 'Expired', 'Created'],
            array_map(fn (BatchResponse $batch): array => [
                $batch->id,
                $batch->processingStatus,
                $batch->requestCounts->processing,
                $batch->requestCounts->succeeded,
                $batch->requestCounts->errored,
                $batch->requestCounts->canceled,
                $batch->requestCounts->expired,
                $batch->createdAt,
            ], $list->data),
        );

        return self::SUCCESS;
    }

    private function show(BatchResponse $batch): void
    {
        $this->components->twoColumnDetail('ID', $batch->id);
        $this->components->twoColumnDetail('Status', $batch->processingStatus);

        $counts = $batch->requestCounts;

        foreach (['Processing' => $counts->processing, 'Succeeded' => $counts->succeeded, 'Errored' => $counts->errored, 'Canceled' => $counts->canceled, 'Expired' => $counts->expired] as $label => $count) {
            $this->components->twoColumnDetail($label, (string) $count);
        }

        $this->components->twoColumnDetail('Created', $batch->createdAt);
        $this->components->twoColumnDetail('Expires', $batch->expiresAt);
        $this->components->twoColumnDetail('Ended', $batch->endedAt ?? '-');
    }
}
