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
class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, static function (): Client {
            $apiKey = config('anthropic.api_key');
            $authToken = self::nonBlankString(config('anthropic.auth_token'));

            if (! is_string($apiKey) && $authToken === null) {
                throw ApiKeyIsMissing::create();
            }

            $guzzleOptions = ['timeout' => config('anthropic.request_timeout', 30)];
            $connectTimeout = config('anthropic.connect_timeout');

            if (is_numeric($connectTimeout) && (float) $connectTimeout > 0) {
                $guzzleOptions['connect_timeout'] = (float) $connectTimeout;
            }

            $factory = Anthropic::factory()->withHttpClient(new \GuzzleHttp\Client($guzzleOptions));

            // One credential, as in the official SDKs: the key when one is set, the token otherwise. A token
            // exported in the shell for another tool then never rides along with the app's key.
            if (is_string($apiKey) && (trim($apiKey) !== '' || $authToken === null)) {
                $factory = $factory->withApiKey($apiKey);
            } elseif ($authToken !== null) {
                $factory = $factory->withAuthToken($authToken);
            }

            $workspaceId = self::nonBlankString(config('anthropic.workspace_id'));

            if ($workspaceId !== null) {
                $factory = $factory->withWorkspaceId($workspaceId);
            }

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

            // A blank value, such as an empty ANTHROPIC_API_VERSION= line in .env, keeps the client's default.
            $apiVersion = self::nonBlankString(config('anthropic.api_version'));

            if ($apiVersion !== null) {
                $factory = $factory->withApiVersion($apiVersion);
            }

            $baseUrl = self::nonBlankString(config('anthropic.base_url'));

            if ($baseUrl !== null) {
                // ANTHROPIC_BASE_URL leaves out the API's /v1, as the official SDKs read it; withBaseUrl() adds it.
                $factory = $factory->withBaseUrl($baseUrl);
            }

            // Applied last, so a header set here wins over the ones the keys above produce.
            $headers = config('anthropic.headers', []);

            foreach (is_array($headers) ? $headers : [] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_int($value) || is_float($value))) {
                    $factory = $factory->withHttpHeader($name, (string) $value);
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
            ], 'anthropic-config');

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

    /**
     * A config value that is a non-blank string, trimmed, or null.
     */
    private static function nonBlankString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
