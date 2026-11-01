<?php

declare(strict_types=1);

namespace Anthropic\Laravel;

use Anthropic\Contracts\ClientContract;
use Anthropic\Laravel\Exceptions\ApiKeyIsMissing;
use Anthropic\Laravel\Testing\AnthropicFake;
use Illuminate\Support\MultipleInstanceManager;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The facade's root: the default connection (the top-level keys of `config/anthropic.php`), the named ones under
 * `connections`, each inheriting what it doesn't set, and clients for a key of the app's choosing. Calls it doesn't
 * know go to the default connection.
 *
 * @mixin ClientContract
 */
class AnthropicManager extends MultipleInstanceManager
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The name of the connection the top-level keys configure.
     */
    public const DEFAULT = 'default';

    /**
     * The names of the manager's own clients, which `connections` can't use.
     */
    private const RESERVED = ['organization', 'enterprise', 'compliance'];

    /**
     * The fake every connection answers from, once `Anthropic::fake()` hands it over.
     */
    private ?AnthropicFake $fake = null;

    /**
     * The organization, enterprise and compliance clients, by name.
     *
     * @var array<string, ClientContract>
     */
    private array $keyed = [];

    private string $default = self::DEFAULT;

    /**
     * The named connection's client; the default one without a name.
     */
    public function connection(?string $name = null): ClientContract
    {
        /** @var ClientContract */
        return $this->instance($name);
    }

    /**
     * The named connection's client, from the fake once `Anthropic::fake()` handed it over.
     *
     * @param  string|null  $name
     */
    public function instance($name = null): mixed
    {
        $name = $name ?: $this->getDefaultInstance();

        if (in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("[{$name}] is the manager's own client: call Anthropic::{$name}(), not Anthropic::connection('{$name}').");
        }

        if ($this->fake instanceof AnthropicFake) {
            // A name the config doesn't hold throws under the fake too, so a typo can't pass in tests.
            if ($this->getInstanceConfig($name) === null) {
                throw new InvalidArgumentException("Instance [{$name}] is not defined.");
            }

            return $this->fake->onConnection($name);
        }

        return parent::instance($name);
    }

    /**
     * A client of the default connection's settings with another API key, such as a tenant's. A new one on every call,
     * never cached, so a key never outlives the request or job it was meant for. It sends no `workspace_id`: the
     * default one belongs to the app's key, and the API answers another key sent with it with a 404.
     */
    public function withApiKey(#[SensitiveParameter] string $apiKey): ClientContract
    {
        // A tenant's empty key column would otherwise build a client that sends no credential at all.
        if (trim($apiKey) === '') {
            throw ApiKeyIsMissing::forTenant();
        }

        if ($this->fake instanceof AnthropicFake) {
            return $this->fake->onConnection(self::DEFAULT);
        }

        return ClientBuilder::make($this->app, [...$this->defaultSettings(), 'api_key' => $apiKey, 'auth_token' => null, 'workspace_id' => null], self::DEFAULT);
    }

    /**
     * The client of the Admin API key (`admin_key`), for the organization's own endpoints.
     */
    public function organization(): ClientContract
    {
        return $this->keyed('organization', 'admin_key', 'ANTHROPIC_ADMIN_KEY', bearer: false);
    }

    /**
     * The client of the Enterprise API key (`enterprise_key`).
     */
    public function enterprise(): ClientContract
    {
        return $this->keyed('enterprise', 'enterprise_key', 'ANTHROPIC_ENTERPRISE_KEY', bearer: false);
    }

    /**
     * The client of the Compliance API key (`compliance_key`), sent as a bearer token.
     */
    public function compliance(): ClientContract
    {
        return $this->keyed('compliance', 'compliance_key', 'ANTHROPIC_COMPLIANCE_API_KEY', bearer: true);
    }

    /**
     * Forgets a connection's client, so the next call builds it from the config again; every named connection and the
     * manager's own clients without a name. The queue worker and Octane call it between jobs and requests. The default
     * connection is the container's `ClientContract`, which stays as it was resolved.
     */
    public function forgetConnection(?string $name = null): static
    {
        if ($name === null) {
            $this->instances = [];
            $this->keyed = [];

            return $this;
        }

        $this->forgetInstance($name);
        unset($this->keyed[$name]);

        return $this;
    }

    /**
     * Hands every connection the fake, from `Anthropic::fake()`.
     *
     * @internal
     */
    public function useFake(AnthropicFake $fake): void
    {
        $this->fake = $fake;
    }

    public function getDefaultInstance(): string
    {
        return $this->default;
    }

    /**
     * @param  string  $name
     */
    public function setDefaultInstance($name): void
    {
        $this->default = $name;
    }

    /**
     * The connection's settings with its driver, or null for a name `connections` doesn't hold.
     *
     * @param  string  $name
     * @return array<array-key, mixed>|null
     */
    public function getInstanceConfig($name): ?array
    {
        $connections = $this->config->get('anthropic.connections');
        $connection = is_array($connections) ? ($connections[$name] ?? null) : null;

        if ($name !== self::DEFAULT && ! is_array($connection)) {
            return null;
        }

        return ['driver' => 'anthropic', ...$this->settings($name), 'name' => $name];
    }

    /**
     * The default connection is the container's binding, so an app's `extend(ClientContract::class, ...)` reaches
     * `Anthropic::` too; a named one is built from its settings.
     *
     * @param  string  $name
     */
    protected function resolve($name): mixed
    {
        return $name === self::DEFAULT ? $this->app->make(ClientContract::class) : parent::resolve($name);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    protected function createAnthropicDriver(#[SensitiveParameter] array $config): ClientContract
    {
        $name = is_string($config['name'] ?? null) ? $config['name'] : self::DEFAULT;

        if (! ClientBuilder::hasCredential($config)) {
            throw ApiKeyIsMissing::forConnection($name);
        }

        return ClientBuilder::make($this->app, $config, $name);
    }

    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call($method, #[SensitiveParameter] $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->connection()->{$method}(...$parameters);
    }

    /**
     * The settings of a connection: the top-level keys without `connections`, with a named connection's over them.
     * A shallow merge: a connection's `beta` or `headers` replaces the top level's rather than adding to it, and a
     * connection that sets either credential inherits neither.
     *
     * @return array<array-key, mixed>
     */
    private function settings(string $name): array
    {
        $config = $this->config->get('anthropic', []);
        $config = is_array($config) ? $config : [];
        $connections = is_array($config['connections'] ?? null) ? $config['connections'] : [];
        unset($config['connections']);

        $own = $name === self::DEFAULT ? [] : ($connections[$name] ?? []);
        $own = is_array($own) ? $own : [];

        if (array_key_exists('api_key', $own) || array_key_exists('auth_token', $own)) {
            unset($config['api_key'], $config['auth_token']);
        }

        return array_replace($config, $own);
    }

    /**
     * The default connection's settings, with laravel/ai's key and URL when this package has no credential.
     *
     * @return array<array-key, mixed>
     */
    private function defaultSettings(): array
    {
        return ClientBuilder::withLaravelAi($this->settings(self::DEFAULT));
    }

    /**
     * A client of the default settings with another key from the config, cached until the connections are forgotten.
     * The default connection's workspace stays behind: these keys belong to the organization, not a workspace.
     */
    private function keyed(string $name, string $key, string $env, bool $bearer): ClientContract
    {
        if ($this->fake instanceof AnthropicFake) {
            return $this->fake->onConnection($name);
        }

        if (isset($this->keyed[$name])) {
            return $this->keyed[$name];
        }

        $settings = $this->defaultSettings();
        $value = $settings[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw ApiKeyIsMissing::forKey($key, $env);
        }

        $credential = $bearer ? ['api_key' => null, 'auth_token' => $value] : ['api_key' => $value, 'auth_token' => null];

        return $this->keyed[$name] = ClientBuilder::make($this->app, [...$settings, ...$credential, 'workspace_id' => null], $name);
    }
}
