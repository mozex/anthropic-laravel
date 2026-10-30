<?php

declare(strict_types=1);

namespace Tests;

use Anthropic\Laravel\AnthropicServiceProvider;
use Anthropic\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class, AnthropicServiceProvider::class];
    }
}
