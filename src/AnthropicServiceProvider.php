<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * The eager provider: what a web request needs before the client is ever resolved. The client, the manager, the
 * config and the commands stay in the deferred `ServiceProvider`.
 *
 * @internal
 */
class AnthropicServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $events = $this->app->make(Dispatcher::class);

        // A worker or Octane keeps the manager between jobs and requests: its named connections and its own clients
        // are built again from the config of the next one (the default connection is the container's binding, kept).
        // Only a manager something already resolved, so a loop that never uses the client never builds it.
        $forget = function (): void {
            if ($this->app->resolved(AnthropicManager::class)) {
                $this->app->make(AnthropicManager::class)->forgetConnection();
            }
        };

        $events->listen(Looping::class, $forget);
        $events->listen('Laravel\Octane\Contracts\OperationTerminated', $forget);
    }
}
