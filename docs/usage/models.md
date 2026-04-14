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
    $model->id;             // 'claude-sonnet-4-6'
    $model->displayName;    // 'Claude Sonnet 4.6'
    $model->createdAt;      // '2025-05-14T00:00:00Z'
    $model->maxInputTokens; // 200000
    $model->maxTokens;      // 64000
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

$response->id;             // 'claude-sonnet-4-6'
$response->displayName;    // 'Claude Sonnet 4.6'
$response->createdAt;      // '2025-05-14T00:00:00Z'
$response->maxInputTokens; // 200000
$response->maxTokens;      // 64000
```

## Capabilities

Each model reports what it supports through a `capabilities` object. The common fields are typed:

```php
$model = Anthropic::models()->retrieve('claude-sonnet-4-6');

$model->capabilities->batch->supported;
$model->capabilities->citations->supported;
$model->capabilities->codeExecution->supported;
$model->capabilities->imageInput->supported;
$model->capabilities->pdfInput->supported;
$model->capabilities->structuredOutputs->supported;
$model->capabilities->thinking->supported;
```

Context management strategies are date-versioned and exposed as a map, so new versions Anthropic ships show up without a package update:

```php
foreach ($model->capabilities->contextManagement->strategies as $name => $strategy) {
    $name;                // 'clear_thinking_20251015'
    $strategy->supported; // true
}
```

A practical Laravel-flavored pattern: pick a model at runtime based on what your feature needs.

```php
use Illuminate\Support\Facades\Cache;

$model = Cache::remember('anthropic.pdf_model', now()->addDay(), function () {
    $models = Anthropic::models()->list()->data;

    foreach ($models as $model) {
        if ($model->capabilities->pdfInput->supported) {
            return $model->id;
        }
    }

    return 'claude-sonnet-4-6';
});
```

---

For available model IDs and capabilities, see the [Models page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/models) or the [Models API reference](https://platform.claude.com/docs/en/api/models/list) on the Anthropic docs.
