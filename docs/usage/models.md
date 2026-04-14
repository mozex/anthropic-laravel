---
title: Models
weight: 8
---

List available Claude models or retrieve a specific one. Useful for model selectors, availability checks, or programmatic discovery.

## Listing models

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::models()->list();

foreach ($response->data as $model) {
    $model->id;          // 'claude-sonnet-4-6'
    $model->displayName; // 'Claude Sonnet 4.6'
    $model->createdAt;   // '2025-05-14T00:00:00Z'
}
```

Response includes pagination metadata:

```php
$response->firstId; // 'claude-sonnet-4-6'
$response->lastId;  // 'claude-haiku-4-5'
$response->hasMore; // true
```

## Pagination

Default limit is 20, max is 1000. Use `after_id` for forward pagination and `before_id` for backward:

```php
$page1 = Anthropic::models()->list(['limit' => 5]);

if ($page1->hasMore) {
    $page2 = Anthropic::models()->list([
        'limit' => 5,
        'after_id' => $page1->lastId,
    ]);
}
```

## Building a model selector

Cache the model list so you're not hitting the API on every request:

```php
use Illuminate\Support\Facades\Cache;

$models = Cache::remember('anthropic.models', now()->addHour(), function () {
    return Anthropic::models()->list()->data;
});

// In a Blade form
<select name="model">
    @foreach ($models as $model)
        <option value="{{ $model->id }}">{{ $model->displayName }}</option>
    @endforeach
</select>
```

## Retrieving a single model

```php
$response = Anthropic::models()->retrieve('claude-sonnet-4-6');

$response->id;          // 'claude-sonnet-4-6'
$response->displayName; // 'Claude Sonnet 4.6'
$response->createdAt;   // '2025-05-14T00:00:00Z'
```

---

For available model IDs and capabilities, see the [Models page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/models) or the [Models API reference](https://platform.claude.com/docs/en/api/models/list) on the Anthropic docs.
