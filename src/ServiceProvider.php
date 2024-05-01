<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic;
use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Commands\InstallCommand;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * @internal
 */
final class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, static function (): Client {
            $apiKey = config('anthropic.api_key');

            if (! is_string($apiKey)) {
                throw ApiKeyIsMissing::create();
            }

            return Anthropic::factory()
                ->withApiKey($apiKey)
                ->withHttpHeader('anthropic-version', '2023-06-01')
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('anthropic.request_timeout', 30)]))
                ->make();
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
            ]);

            $this->commands([
                InstallCommand::class,
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
        ];
    }
}
