<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Support;

use Anthropic\Client;
use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\ClientBuilder;
use Composer\InstalledVersions;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * What `php artisan about` shows in its Anthropic section.
 *
 * @internal
 */
class About
{
    /**
     * The versions, whether a credential is set (never its value), and what the default client sends, read back from
     * it.
     *
     * @return array<string, string>
     */
    public static function data(Container $app): array
    {
        $config = config('anthropic', []);
        $config = is_array($config) ? $config : [];
        $connections = is_array($config['connections'] ?? null) ? array_keys($config['connections']) : [];

        $about = [
            'Version' => self::version('mozex/anthropic-laravel'),
            'Client version' => self::version('mozex/anthropic-php'),
            'Credential' => match (true) {
                ClientBuilder::hasCredential($config) => is_string($config['api_key'] ?? null) && trim($config['api_key']) !== '' ? 'API key' : 'auth token',
                ClientBuilder::hasCredential(ClientBuilder::withLaravelAi($config)) => 'laravel/ai\'s key',
                default => '<fg=yellow;options=bold>MISSING</>',
            },
            'Request timeout' => (is_numeric($config['request_timeout'] ?? null) ? (string) $config['request_timeout'] : '30').'s',
            'Connections' => $connections === [] ? 'default' : 'default, '.implode(', ', array_map(strval(...), $connections)),
        ];

        try {
            $client = $app->make(ClientContract::class);
        } catch (Throwable $e) {
            // A missing key already shows above; another config error is worth its own line.
            if (ClientBuilder::hasCredential(ClientBuilder::withLaravelAi($config))) {
                $about['Client'] = '<fg=red;options=bold>NOT BUILT</> '.$e->getMessage();
            }

            return $about;
        }

        if ($client instanceof Client) {
            $settings = $client->settings();

            $about['Base URL'] = $settings->baseUri;
            $about['API version'] = $settings->apiVersion;
            $about['Betas'] = $settings->betas === [] ? 'none' : implode(', ', $settings->betas);
            $about['Retries'] = (string) $settings->maxRetries;
        }

        return $about;
    }

    /**
     * A package's version, or `unknown` for one Composer didn't install, as in a package's own checkout.
     */
    private static function version(string $package): string
    {
        return InstalledVersions::isInstalled($package) ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown') : 'unknown';
    }
}
