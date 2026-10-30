<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Exceptions;

use InvalidArgumentException;

/**
 * @internal
 */
class ApiKeyIsMissing extends InvalidArgumentException
{
    /**
     * Create a new exception instance.
     */
    public static function create(): self
    {
        return new self(
            'The Anthropic API Key is missing. Please publish the [anthropic.php] configuration file and set the [api_key].'
        );
    }

    /**
     * Create the exception for a named connection without a credential.
     */
    public static function forConnection(string $name): self
    {
        return new self("The Anthropic [{$name}] connection has no [api_key] or [auth_token]. Set one under [connections.{$name}] in config/anthropic.php.");
    }

    /**
     * Create the exception for a blank key handed to `withApiKey()`.
     */
    public static function forTenant(): self
    {
        return new self('The API key passed to Anthropic::withApiKey() is blank.');
    }

    /**
     * Create the exception for a key one of the manager's own clients needs.
     */
    public static function forKey(string $key, string $env): self
    {
        return new self("The Anthropic [{$key}] is missing. Add '{$key}' => env('{$env}') to config/anthropic.php and set {$env} in your .env file.");
    }
}
