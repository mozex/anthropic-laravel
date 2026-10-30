<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Queue;

use Anthropic\Laravel\Events\ResponseReceived;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Testing\Fakes\EventFake;
use WeakMap;

/**
 * Keeps what's left of the token rate limit after each answer a throttled job with a budget gets, under its key until
 * the limit resets, for `ThrottlesAnthropic::budget()` to read before the next job calls.
 *
 * @internal
 */
class RecordsBudget
{
    /**
     * The dispatchers it listens on, so it's added once per worker or Octane process, by the first throttled job with
     * a budget, and an app without one keeps `ResponseReceived` unbuilt when nothing else listens.
     *
     * @var WeakMap<Dispatcher, true>|null
     */
    private static ?WeakMap $listening = null;

    public static function listenOn(Dispatcher $events): void
    {
        // Event::fake() passes listeners on to the dispatcher it wraps, which is the one to count.
        if ($events instanceof EventFake) {
            $events = $events->dispatcher;
        }

        self::$listening ??= new WeakMap;

        if (isset(self::$listening[$events])) {
            return;
        }

        self::$listening[$events] = true;
        $events->listen(ResponseReceived::class, [self::class, 'handle']);
    }

    public function handle(ResponseReceived $event): void
    {
        $key = app(ThrottleScope::class)->key;
        $remaining = $event->meta['anthropic-ratelimit-tokens-remaining'] ?? null;
        $reset = $event->meta['anthropic-ratelimit-tokens-reset'] ?? null;

        if ($key === null || ! is_int($remaining) || ! is_string($reset)) {
            return;
        }

        // A header it can't read leaves the budget as it was: a listener that threw would fail the call itself.
        $resetAt = strtotime($reset);
        $seconds = $resetAt === false ? 0 : $resetAt - Date::now()->getTimestamp();

        if ($seconds > 0) {
            Cache::put("anthropic:budget:{$key}", ['remaining' => $remaining, 'reset' => $resetAt], $seconds);
        }
    }
}
