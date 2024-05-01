<p align="center">
    <p align="center">
        <a href="https://github.com/anthropic-php/laravel/actions"><img alt="GitHub Workflow Status (master)" src="https://img.shields.io/github/actions/workflow/status/anthropic-php/laravel/tests.yml?branch=main&label=tests&style=round-square"></a>
        <a href="https://packagist.org/packages/anthropic-php/laravel"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/anthropic-php/laravel"></a>
        <a href="https://packagist.org/packages/anthropic-php/laravel"><img alt="Latest Version" src="https://img.shields.io/packagist/v/anthropic-php/laravel"></a>
        <a href="https://packagist.org/packages/anthropic-php/laravel"><img alt="License" src="https://img.shields.io/github/license/anthropic-php/laravel"></a>
    </p>
</p>

------
**Anthropic PHP** for Laravel is a community-maintained PHP API client that allows you to interact with the [Anthropic API](https://beta.anthropic.com/docs/api-reference/introduction). If you or your business relies on this package, it's important to support the developers who have contributed their time and effort to create and maintain this valuable tool:

- Nuno Maduro: **[github.com/sponsors/nunomaduro](https://github.com/sponsors/nunomaduro)**
- Sandro Gehri: **[github.com/sponsors/gehrisandro](https://github.com/sponsors/gehrisandro)**

> **Note:** This repository contains the integration code of the **Anthropic PHP** for Laravel. If you want to use the **Anthropic PHP** client in a framework-agnostic way, take a look at the [anthropic-php/client](https://github.com/anthropic-php/client) repository.

## Get Started

> **Requires [PHP 8.1+](https://php.net/releases/)**

First, install Anthropic via the [Composer](https://getcomposer.org/) package manager:

```bash
composer require anthropic-php/laravel
```

Next, execute the install command:

```bash
php artisan anthropic:install
```

This will create a `config/anthropic.php` configuration file in your project, which you can modify to your needs
using environment variables.
Blank environment variables for the Anthropic API key and organization id are already appended to your `.env` file.

```env
ANTHROPIC_API_KEY=sk-...
ANTHROPIC_ORGANIZATION=org-...
```

Finally, you may use the `Anthropic` facade to access the Anthropic API:

```php
use Anthropic\Laravel\Facades\Anthropic;

$result = Anthropic::chat()->create([
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'user', 'content' => 'Hello!'],
    ],
]);

echo $result->choices[0]->message->content; // Hello! How can I assist you today?
```

## Configuration

Configuration is done via environment variables or directly in the configuration file (`config/anthropic.php`).

### Anthropic API Key and Organization

Specify your Anthropic API Key and organization. This will be
used to authenticate with the Anthropic API - you can find your API key
and organization on your Anthropic dashboard, at https://anthropic.com.

```env
ANTHROPIC_API_KEY=
ANTHROPIC_ORGANIZATION=
```

### Request Timeout

The timeout may be used to specify the maximum number of seconds to wait
for a response. By default, the client will time out after 30 seconds.

```env
ANTHROPIC_REQUEST_TIMEOUT=
```

## Usage

For usage examples, take a look at the [anthropic-php/client](https://github.com/anthropic-php/client) repository.

## Testing

The `Anthropic` facade comes with a `fake()` method that allows you to fake the API responses.

The fake responses are returned in the order they are provided to the `fake()` method.

All responses are having a `fake()` method that allows you to easily create a response object by only providing the parameters relevant for your test case.

```php
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Completions\CreateResponse;

Anthropic::fake([
    CreateResponse::fake([
        'choices' => [
            [
                'text' => 'awesome!',
            ],
        ],
    ]),
]);

$completion = Anthropic::completions()->create([
    'model' => 'gpt-3.5-turbo-instruct',
    'prompt' => 'PHP is ',
]);

expect($completion['choices'][0]['text'])->toBe('awesome!');
```

After the requests have been sent there are various methods to ensure that the expected requests were sent:

```php
// assert completion create request was sent
Anthropic::assertSent(Completions::class, function (string $method, array $parameters): bool {
    return $method === 'create' &&
        $parameters['model'] === 'gpt-3.5-turbo-instruct' &&
        $parameters['prompt'] === 'PHP is ';
});
```

For more testing examples, take a look at the [anthropic-php/client](https://github.com/anthropic-php/client#testing) repository.

---

Anthropic PHP for Laravel is an open-sourced software licensed under the **[MIT license](https://opensource.org/licenses/MIT)**.
