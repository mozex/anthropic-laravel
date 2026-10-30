<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic;
use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Commands\InstallCommand;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Anthropic\Middleware\RefusalFallbackMiddleware;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use InvalidArgumentException;

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

            $stack = HandlerStack::create();
            $middleware = config('anthropic.http.middleware', []);

            if (is_string($middleware)) {
                $middleware = [$middleware];
            }

            foreach (is_array($middleware) ? $middleware : [] as $entry) {
                $resolved = is_string($entry) ? $app->make($entry) : $entry;

                if (! is_callable($resolved)) {
                    throw new InvalidArgumentException('Each anthropic.http.middleware entry must be a Guzzle middleware: an invokable class name or a callable.');
                }

                $stack->push($resolved);
            }

            $guzzleOptions['handler'] = $stack;

            $factory = Anthropic::factory()->withHttpClient(new \GuzzleHttp\Client($guzzleOptions));

            $fallbackModels = config('anthropic.fallback_models', []);

            // A config written by hand as env('ANTHROPIC_FALLBACK_MODELS') gives the comma-separated string itself.
            if (is_string($fallbackModels)) {
                $fallbackModels = explode(',', $fallbackModels);
            }

            $fallbackModels = array_values(array_filter(array_map(
                static fn (mixed $model): string => is_string($model) ? trim($model) : '',
                is_array($fallbackModels) ? $fallbackModels : [],
            )));

            if ($fallbackModels !== []) {
                // No shared FallbackState: a worker's singleton would pin every user to a fallback after one refusal.
                $factory = $factory->withMiddleware(new RefusalFallbackMiddleware($fallbackModels));
            }

            $maxRetries = config('anthropic.max_retries');

            if (is_numeric($maxRetries) && (int) $maxRetries > 0) {
                $factory = $factory->withMaxRetries((int) $maxRetries);
            }

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
