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
    $model->id;             // 'claude-sonnet-5-5'
    $model->displayName;    // 'Claude Sonnet 5.5'
    $model->createdAt;      // '2026-09-28T00:00:00Z'
    $model->maxInputTokens; // 1000000
    $model->maxTokens;      // 128000
}
```

Response includes pagination metadata:

```php
$response->firstId; // 'claude-haiku-5-5'
$response->lastId;  // 'claude-haiku-4-5-20251001'
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

`iterate()` walks every page for you and yields one model at a time, requesting each page only when the loop gets there. Wrap it in a `LazyCollection` for the collection methods:

```php
use Illuminate\Support\LazyCollection;

$sonnets = LazyCollection::make(fn () => Anthropic::models()->iterate(['limit' => 100]))
    ->where('line', 'sonnet')
    ->pluck('id')
    ->all();
```

Each pass over a `LazyCollection` runs the requests again, so end the chain with `->all()` when you use the result more than once.

The list holds active and deprecated models. Pass `lifecycle` to pick the stages, such as `['lifecycle' => ['retired']]` for the retired ones, which only show up when asked for.

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
$response = Anthropic::models()->retrieve('claude-sonnet-5-5');

$response->id;             // 'claude-sonnet-5-5'
$response->displayName;    // 'Claude Sonnet 5.5'
$response->createdAt;      // '2026-09-28T00:00:00Z'
$response->maxInputTokens; // 1000000
$response->maxTokens;      // 128000
```

`maxInputTokens`, `maxTokens` and `capabilities` can be `null`, so give them a fallback.

## Catching retirements early

Each model reports its `lifecycle` (`'active'`, `'deprecated'` or `'retired'`), its family `line` (`'sonnet'`, `'opus'` ...), and `deprecatedAt` and `retiresAt` dates. A scheduled check warns you before a model your app uses stops answering:

```php
// routes/console.php
use Anthropic\Laravel\Facades\Anthropic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    foreach (config('services.anthropic.models', ['claude-sonnet-5-5']) as $id) {
        $model = Anthropic::models()->retrieve($id);

        if (in_array($model->lifecycle, ['deprecated', 'retired'], true)) {
            Log::warning('Claude model is being retired', [
                'model' => $model->id,
                'lifecycle' => $model->lifecycle,
                'retires_at' => $model->retiresAt,
            ]);
        }
    }
})->daily();
```

A deprecated model whose `retiresAt` has already passed is overdue to retire, so treat it as gone.

To move to a new model gradually, let [Pennant](https://laravel.com/docs/pennant) pick it per user, then finish or undo the rollout without a deploy, with `Feature::activateForEveryone('claude-sonnet-5-5')` or `deactivateForEveryone()`:

```php
use Anthropic\Laravel\Facades\Anthropic;
use App\Models\User;
use Illuminate\Support\Lottery;
use Laravel\Pennant\Feature;

// AppServiceProvider::boot()
Feature::define('claude-sonnet-5-5', fn (User $user) => Lottery::odds(1, 10)->choose());

// where you call Claude; in a queued job, name the user: Feature::for($user)->active(...)
$model = Feature::active('claude-sonnet-5-5') ? 'claude-sonnet-5-5' : 'claude-sonnet-4-6';

$response = Anthropic::messages()->create(['model' => $model, 'max_tokens' => 1024, 'messages' => [...]]);
```

Pennant stores each user's result, so a user stays on one model while you compare their answers.

## Capabilities

Each model reports what it supports through a `capabilities` object. The common fields are typed:

```php
$model = Anthropic::models()->retrieve('claude-sonnet-5-5');

$model->capabilities?->batch->supported;
$model->capabilities?->citations->supported;
$model->capabilities?->codeExecution->supported;
$model->capabilities?->imageInput->supported;
$model->capabilities?->pdfInput->supported;
$model->capabilities?->structuredOutputs->supported;
$model->capabilities?->thinking->supported;
$model->capabilities?->thinking->types->betweenTools?->supported;
$model->capabilities?->effort->xhigh?->supported;
$model->capabilities?->serverTools?->webSearch->supported;
```

Context management strategies are date-versioned and exposed as a map, so new versions Anthropic ships show up without a package update:

```php
foreach ($model->capabilities?->contextManagement->strategies ?? [] as $name => $strategy) {
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
        if ($model->capabilities?->pdfInput->supported) {
            return $model->id;
        }
    }

    return 'claude-sonnet-5-5';
});
```

---

For every capability field, the beta-only `allowedFallbackModels` and compaction capability, and backward pagination, see the [Models page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/models) or the [Models API reference](https://platform.claude.com/docs/en/api/models/list) on the Anthropic docs.
