---
title: Raw Requests
weight: 11
---

`Anthropic::request()` calls any API path, for an endpoint the package has no method for yet. Your key, the config's headers and retries, and `Anthropic::withOptions()` all apply, and an error status throws the same exceptions as every other call.

```php
use Anthropic\Laravel\Facades\Anthropic;
use Illuminate\Support\Facades\Cache;

// The first page; pass its next_page back as 'page' for the next one.
$skills = Cache::remember('anthropic.skills', now()->addHour(), function () {
    return Anthropic::request('GET', 'skills', ['limit' => 100])->data['data'];
});
```

`GET` and `DELETE` send the parameters in the query string, `POST` and `PUT` in a JSON body, and a `betas` parameter becomes the `anthropic-beta` header, as on every other call. `->data` is the decoded JSON (or the body's text when it isn't JSON), `->status` is the HTTP status, and `->meta()` has the request ID and rate-limit headers. The path works with or without the `/v1` the API reference prints.

## Testing

Queue a `RawResponse::fake()` with the whole body you want back, and assert on `Raw::class`:

```php
use Anthropic\Resources\Raw;
use Anthropic\Responses\Raw\RawResponse;

Anthropic::fake([
    RawResponse::fake(['data' => [], 'next_page' => null]),
]);

$this->get('/skills')->assertOk();

Anthropic::assertSent(Raw::class, fn (string $method, string $httpMethod, string $path): bool => $path === 'skills');
```

The fake records the path exactly as your code passed it, so `/v1/skills` and `skills` assert differently. A status other than 200 is a named argument: `RawResponse::fake('', status: 204)` fakes an answer without a body.

---

For the response shape, paths and what's recorded, see the [Raw Requests page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/raw-requests) or the [Anthropic API reference](https://platform.claude.com/docs/en/api/overview).
