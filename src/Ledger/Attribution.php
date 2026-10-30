<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * Who a call is billed to and what it's for, kept in hidden Context by `Anthropic::for()`, so it follows the request
 * into the jobs it dispatches and stays out of the logs. Read when a call's usage is recorded, never from `auth()`.
 *
 * @internal
 */
class Attribution
{
    public static function set(?Model $billable, ?string $feature): void
    {
        Context::addHidden([
            'anthropic_billable_type' => $billable?->getMorphClass(),
            'anthropic_billable_id' => $billable?->getKey(),
            'anthropic_feature' => $feature,
        ]);
    }

    /**
     * Named as `UsageRecorded`'s parameters, so it spreads into one.
     *
     * @return array{billableType: ?string, billableId: int|string|null, feature: ?string}
     */
    public static function current(): array
    {
        $type = Context::getHidden('anthropic_billable_type');
        $id = Context::getHidden('anthropic_billable_id');
        $feature = Context::getHidden('anthropic_feature');

        return [
            'billableType' => is_string($type) ? $type : null,
            'billableId' => is_int($id) || is_string($id) ? $id : null,
            'feature' => is_string($feature) ? $feature : null,
        ];
    }
}
