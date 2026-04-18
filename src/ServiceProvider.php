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

            $factory = Anthropic::factory()
                ->withApiKey($apiKey)
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('anthropic.request_timeout', 30)]));

            $beta = config('anthropic.beta', []);

            if (is_array($beta) && $beta !== []) {
                $betaHeader = implode(',', array_filter(array_map(
                    static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                    $beta,
                )));

                if ($betaHeader !== '') {
                    $factory = $factory->withHttpHeader('anthropic-beta', $betaHeader);
                }
            }

            return $factory->make();
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
