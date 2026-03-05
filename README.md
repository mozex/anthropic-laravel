[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)
[![GitHub Tests Workflow Status](https://img.shields.io/github/actions/workflow/status/mozex/anthropic-laravel/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/anthropic-laravel/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/anthropic-laravel.svg?style=flat-square)](https://packagist.org/packages/mozex/anthropic-laravel)

------
**Anthropic Laravel** is a community-maintained PHP API client that allows you to interact with the [Anthropic API](https://docs.anthropic.com/claude/docs/intro-to-claude). This package is based on the excellent work of [Nuno Maduro](https://github.com/nunomaduro) and [Sandro Gehri](https://github.com/gehrisandro).

> **Note:** This repository contains the integration code of the **Anthropic PHP** for Laravel. If you want to use the **Anthropic PHP** client in a framework-agnostic way, take a look at the [mozex/anthropic-php](https://github.com/mozex/anthropic-php) repository.

## Table of Contents

- [Support Us](#support-us)
- [Get Started](#get-started)
- [Configuration](#configuration)
- [Usage](#usage)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [License](#license)

## Support us

Creating and maintaining open-source projects requires significant time and effort. Your support will help enhance the project and enable further contributions to the PHP community.

Sponsorship can be made through the [GitHub Sponsors](https://github.com/sponsors/mozex) program. Just click the "**[Sponsor](https://github.com/sponsors/mozex)**" button at the top of this repository. Any amount is greatly appreciated, even a contribution as small as $1 can make a big difference and will go directly towards developing and improving this package.

Thank you for considering sponsoring. Your support truly makes a difference!

## Get Started

> **Requires [PHP 8.2+](https://www.php.net/releases/)**

First, install Anthropic via the [Composer](https://getcomposer.org/) package manager:

```bash
composer require mozex/anthropic-laravel
```

Next, execute the install command:

```bash
php artisan anthropic:install
```

This will create a `config/anthropic.php` configuration file in your project, which you can modify to your needs
using environment variables.
Blank environment variable for the Anthropic API key is already appended to your `.env` file.

```env
ANTHROPIC_API_KEY=sk-...
```

Finally, you may use the `Anthropic` facade to access the Anthropic API:

```php
use Anthropic\Laravel\Facades\Anthropic;

$result = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

echo $result->content[0]->text; // Hello! How can I assist you today?
```

## Configuration

Configuration is done via environment variables or directly in the configuration file (`config/anthropic.php`).

### Anthropic API Key

Specify your Anthropic API Key. This will be used to authenticate with the Anthropic API - you can find your API key on your Anthropic dashboard, at https://console.anthropic.com/settings/keys.

```env
ANTHROPIC_API_KEY=
```

### Request Timeout

The timeout may be used to specify the maximum number of seconds to wait
for a response. By default, the client will time out after 30 seconds.

```env
ANTHROPIC_REQUEST_TIMEOUT=
```

## Usage

For detailed usage examples, take a look at the [mozex/anthropic-php](https://github.com/mozex/anthropic-php) repository.

The following resources are available through the `Anthropic` facade:

```php
use Anthropic\Laravel\Facades\Anthropic;

// Messages (primary API)
Anthropic::messages()->create([...]);
Anthropic::messages()->createStreamed([...]);
Anthropic::messages()->countTokens([...]);

// Models
Anthropic::models()->list();
Anthropic::models()->retrieve('claude-sonnet-4-6');

// Message Batches
Anthropic::batches()->create([...]);
Anthropic::batches()->retrieve('msgbatch_...');
Anthropic::batches()->list();
Anthropic::batches()->cancel('msgbatch_...');
Anthropic::batches()->delete('msgbatch_...');
Anthropic::batches()->results('msgbatch_...');

// Legacy Completions
Anthropic::completions()->create([...]);
```

## Testing

The `Anthropic` facade comes with a `fake()` method that allows you to fake the API responses.

The fake responses are returned in the order they are provided to the `fake()` method.

All responses have a `fake()` method that allows you to easily create a response object by only providing the parameters relevant for your test case.

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Resources\Messages;
use Anthropic\Responses\Messages\CreateResponse;

Anthropic::fake([
    CreateResponse::fake([
        'id' => 'msg_test',
    ]),
]);

$result = Anthropic::messages()->create([
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

expect($result)->id->toBe('msg_test');
```

After the requests have been sent there are various methods to ensure that the expected requests were sent:

```php
// assert a messages create request was sent
Anthropic::assertSent(Messages::class, function (string $method, array $parameters): bool {
    return $method === 'create' &&
        $parameters['model'] === 'claude-sonnet-4-6';
});
```

You can also assert on specific resources:

```php
Anthropic::messages()->assertSent(function (string $method, array $parameters): bool {
    return $method === 'create';
});
```

Other available assertion methods:

```php
// assert that nothing was sent
Anthropic::assertNothingSent();

// assert that a specific resource was not called
Anthropic::assertNotSent(Messages::class);
```

For more testing examples, take a look at the [mozex/anthropic-php](https://github.com/mozex/anthropic-php#testing) repository.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mozex](https://github.com/mozex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
