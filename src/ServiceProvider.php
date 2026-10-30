<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Commands\DoctorCommand;
use Anthropic\Laravel\Commands\InstallCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * @internal
 */
class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, static function (Container $app): Client {
            $config = config('anthropic', []);
            $config = is_array($config) ? $config : [];
            unset($config['connections']);

            return ClientBuilder::make($app, ClientBuilder::withLaravelAi($config), AnthropicManager::DEFAULT);
        });

        $this->app->singleton(AnthropicManager::class, static function (Container $app): AnthropicManager {
            /** @var Application $app */
            return new AnthropicManager($app);
        });

        $this->app->alias(ClientContract::class, 'anthropic');
        $this->app->alias(ClientContract::class, Client::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/anthropic.php' => config_path('anthropic.php'),
            ], 'anthropic-config');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            Client::class,
            ClientContract::class,
            'anthropic',
            AnthropicManager::class,
        ];
    }
}
