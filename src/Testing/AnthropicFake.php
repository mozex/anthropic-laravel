<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Testing;

use Anthropic\Testing\ClientFake;

class AnthropicFake extends ClientFake
{
    /**
     * The container binds the fake itself as the client, so calls through an injected ClientContract are the default
     * connection's, and `assertSentOn('default', ...)` counts them.
     */
    protected ?string $connection = 'default';
}
