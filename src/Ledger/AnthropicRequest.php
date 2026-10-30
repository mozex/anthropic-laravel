<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Ledger;

use Anthropic\Laravel\Events\UsageRecorded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

/**
 * One billed Messages call in the usage ledger (`anthropic_requests`): its tokens as the API counted them, never a
 * price. Pruned by `model:prune --model`, after `anthropic.ledger.prune_after_days`.
 *
 * @property int $input_tokens
 * @property int $output_tokens
 */
class AnthropicRequest extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'anthropic_requests';

    protected $guarded = [];

    /**
     * The row a call's usage makes, as `insert()` takes it. With `usage.iterations` (compaction, the advisor, a
     * server-side fallback), the counts are summed across them, which the top-level counts leave out; the whole usage
     * goes in `usage` too.
     *
     * @return array<string, mixed>
     */
    public static function rowFrom(UsageRecorded $usage): array
    {
        $iterations = array_values(array_filter(is_array($usage->usage['iterations'] ?? null) ? $usage->usage['iterations'] : [], is_array(...)));
        $counted = $iterations === [] ? [$usage->usage] : $iterations;
        $count = fn (string $key): int => array_sum(array_map(fn (array $counts): int => is_int($counts[$key] ?? null) ? $counts[$key] : 0, $counted));
        $text = fn (string $key): ?string => is_string($usage->usage[$key] ?? null) ? $usage->usage[$key] : null;
        $serverTools = $usage->usage['server_tool_use'] ?? null;

        return [
            'connection' => $usage->connection,
            'model' => $usage->model,
            'request_id' => $usage->requestId,
            'batch_id' => $usage->batchId,
            'custom_id' => $usage->customId,
            'input_tokens' => $count('input_tokens'),
            'output_tokens' => $count('output_tokens'),
            'cache_read_input_tokens' => $count('cache_read_input_tokens'),
            'cache_creation_input_tokens' => $count('cache_creation_input_tokens'),
            'server_tool_use' => is_array($serverTools) ? json_encode($serverTools) : null,
            'usage' => json_encode($usage->usage),
            'service_tier' => $text('service_tier'),
            'speed' => $text('speed'),
            'batch' => $usage->batch,
            'completed' => $usage->completed,
            'duration_ms' => $usage->durationMs,
            'billable_type' => $usage->billableType,
            'billable_id' => $usage->billableId,
            'feature' => $usage->feature,
            // When the call was billed, not when a queued listener got to it.
            // In the app's timezone, as every query of the column reads it.
            'created_at' => $usage->at === null ? Date::now() : Date::now()->setTimestamp($usage->at),
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Rows older than `prune_after_days`; none with 0 or a blank, which keep the ledger whole.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('anthropic.ledger.prune_after_days', 90);

        if (! is_numeric($days) || (int) $days < 1) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', Date::now()->subDays((int) $days));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_tool_use' => 'array',
            'usage' => 'array',
            'batch' => 'boolean',
            'completed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
