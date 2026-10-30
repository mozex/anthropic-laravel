<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Commands;

use Anthropic\Laravel\Ledger\AnthropicRequest;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;

class UsageCommand extends Command
{
    protected $signature = 'anthropic:usage
        {--days=30 : How many days back to sum}
        {--by=model : What to group by: model, feature, connection, billable or day}';

    protected $description = 'Sums the usage ledger\'s tokens, by model, feature, connection, billable or day.';

    public function handle(): int
    {
        if (! is_array(config('anthropic.ledger'))) {
            $this->components->error('config/anthropic.php has no `ledger` array: copy it from the package\'s config/anthropic.php, then set ANTHROPIC_LEDGER=true.');

            return self::FAILURE;
        }

        if (config('anthropic.ledger.enabled') !== true) {
            $this->components->error('The usage ledger is off: set ANTHROPIC_LEDGER=true and run the published migration.');

            return self::FAILURE;
        }

        $by = $this->option('by');
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! in_array($by, ['model', 'feature', 'connection', 'billable', 'day'], true) || $days === false) {
            $this->components->error('--by takes model, feature, connection, billable or day, and --days a whole number of at least 1.');

            return self::FAILURE;
        }

        $query = AnthropicRequest::query()
            ->where('created_at', '>=', Date::now()->subDays($days))
            ->selectRaw('count(*) as requests, sum(input_tokens) as input, sum(output_tokens) as output, sum(cache_read_input_tokens) as cache_read, sum(cache_creation_input_tokens) as cache_write')
            ->orderByRaw('sum(input_tokens) + sum(output_tokens) desc');

        // Grouped by plain columns where it can be, and a day each database spells its own way.
        match ($by) {
            'billable' => $query->addSelect(['billable_type', 'billable_id'])->groupBy('billable_type', 'billable_id'),
            'day' => $query->selectRaw($this->day().' as grouped')->groupByRaw($this->day()),
            default => $query->addSelect("{$by} as grouped")->groupBy($by),
        };

        try {
            $rows = $query->get();
        } catch (QueryException) {
            $this->components->error('The anthropic_requests table isn\'t there: publish the migration (--tag=anthropic-migrations) and run php artisan migrate.');

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->components->info("Nothing recorded in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->table(
            [ucfirst($by), 'Requests', 'Input', 'Output', 'Cache read', 'Cache write'],
            $rows->map(fn (AnthropicRequest $row): array => [
                $this->label($row, $by),
                ...array_map(fn (string $column): string => $this->sum($row, $column), ['requests', 'input', 'output', 'cache_read', 'cache_write']),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function label(AnthropicRequest $row, string $by): string
    {
        if ($by === 'billable') {
            $type = $row->getAttribute('billable_type');
            $id = $row->getAttribute('billable_id');

            return is_string($type) && is_scalar($id) ? "{$type}:{$id}" : '-';
        }

        $value = $row->getAttribute('grouped');

        return is_scalar($value) ? (string) $value : '-';
    }

    private function sum(AnthropicRequest $row, string $column): string
    {
        $sum = $row->getAttribute($column);

        return number_format(is_numeric($sum) ? (int) $sum : 0);
    }

    /**
     * @return literal-string
     */
    private function day(): string
    {
        return (new AnthropicRequest)->getConnection()->getDriverName() === 'sqlsrv' ? 'CAST(created_at AS DATE)' : 'DATE(created_at)';
    }
}
