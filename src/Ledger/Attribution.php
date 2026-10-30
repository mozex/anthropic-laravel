<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Repository;

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
        self::context()->addHidden([
            'anthropic_billable_type' => $billable?->getMorphClass(),
            'anthropic_billable_id' => $billable?->getKey(),
            'anthropic_feature' => $feature,
        ]);
    }

    /**
     * A budget `Budget::enforce()` holds the calls after it to, beside the others, replacing one under the same key and
     * window.
     *
     * @param  array{key: string, tokens: int, seconds: int, counts: string}  $budget
     */
    public static function addBudget(array $budget): void
    {
        self::setBudgets([...self::budgets(), "{$budget['key']}:{$budget['seconds']}" => $budget]);
    }

    /**
     * The budgets enforced now, by key and window, for a job to put back when it ends.
     *
     * @return array<array-key, mixed>
     */
    public static function budgets(): array
    {
        $budgets = self::context()->getHidden('anthropic_budgets');

        return is_array($budgets) ? $budgets : [];
    }

    /**
     * @param  array<array-key, mixed>  $budgets
     */
    public static function setBudgets(array $budgets): void
    {
        if ($budgets === []) {
            self::context()->forgetHidden('anthropic_budgets');

            return;
        }

        self::context()->addHidden('anthropic_budgets', $budgets);
    }

    /**
     * Named as `UsageRecorded`'s parameters, so it spreads into one.
     *
     * @return array{billableType: ?string, billableId: int|string|null, feature: ?string, budgets: list<mixed>}
     */
    public static function current(): array
    {
        $type = self::context()->getHidden('anthropic_billable_type');
        $id = self::context()->getHidden('anthropic_billable_id');
        $feature = self::context()->getHidden('anthropic_feature');

        return [
            'billableType' => is_string($type) ? $type : null,
            'billableId' => is_int($id) || is_string($id) ? $id : null,
            'feature' => is_string($feature) ? $feature : null,
            'budgets' => array_values(self::budgets()),
        ];
    }

    /**
     * Resolved on each call, never kept, as Context is scoped to each job and request.
     */
    private static function context(): Repository
    {
        return app(Repository::class);
    }
}
