---
title: Diagnostics
weight: 6
---

Two artisan commands show how the package is set up: `anthropic:doctor` checks it, and Laravel's own `about` lists it.

## anthropic:doctor

```bash
php artisan anthropic:doctor
php artisan anthropic:doctor --connection=support
php artisan anthropic:doctor --offline
```

It checks the default connection, or the one you name, and prints the base URL, API version and betas the client sends:

| Check | Fails when |
|-------|------------|
| Credential | the connection has no key or token |
| API | one page of the models list, which spends no tokens, comes back as an error or doesn't come back |
| Request timeout | `request_timeout` is 0, so a call never times out |
| Queue `retry_after` | the default queue connection's `retry_after` (or a failover connection's members', or a connection Horizon uses) is at most the longest non-streamed call; another connection only warns, and a redis or database connection without one counts as Laravel's 60 seconds |
| Horizon `timeout` | never fails: it warns when a supervisor Horizon runs in this environment has a `timeout` at most that longest call, since a job's own `$timeout` wins over it |
| Fallback models | a model in `fallback_models` is retired or unknown to the API (a deprecated one only warns, with its retirement date, and says when that date has passed) |
| Livewire streaming | never fails: it warns when Livewire and Octane are both installed, since `wire:stream` doesn't work under Octane |

The queue check guards the costly trap. A worker that holds a job longer than the connection's `retry_after` hands it to a second worker while the first is still waiting on Claude, so the job runs twice and the call is paid for twice. A Horizon supervisor whose `timeout` is shorter than the call kills the job after the API has already started working on it.

The longest non-streamed call is `request_timeout` × (`max_retries` + 1) × (1 + the number of `fallback_models`), plus the client's waits between retries: every attempt and every fallback model gets the whole timeout. With the defaults, that's 30 seconds. A `retry-after` the API sends can make a retry wait longer, so leave a margin. A streamed call is different: its `request_timeout` is the longest wait between two chunks, not the whole answer, so a job that streams needs a `$timeout`, and a `retry_after` above it, sized for the longest answer you expect. An SQS connection your jobs use has a visibility timeout in `retry_after`'s place, so the doctor reminds you to check it. Horizon's supervisors are the ones it runs in the current environment, picked the way Horizon picks them; `php artisan anthropic:doctor --env=production` checks production's from CI.

Any failed check exits with a non-zero code, so a deploy pipeline can run it. Warnings don't fail it. `--offline` skips the API and the fallback models, and without a key it only warns about the credential, so a pipeline without one can still check the queues.

## php artisan about

`php artisan about` has an Anthropic section: the package and client versions, the kind of credential in use (an API key, an auth token or laravel/ai's key, never its value), the request timeout, the connections, and the base URL, API version, betas and retries the default client sends.

```bash
php artisan about --only=anthropic
```

## A health check

Laravel's `/up` route dispatches `DiagnosingHealth`, and a listener that throws turns it into a 500. Don't call the API there: load balancers hit `/up` every few seconds, and each call counts against your rate limits. Record the last call that went through and check its age instead:

```php
// AppServiceProvider::boot()
use Anthropic\Laravel\Events\ResponseReceived;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;

Event::listen(function (ResponseReceived $event) {
    if ($event->status < 400) {
        Cache::put('anthropic:last-success', now()->getTimestamp(), now()->addDay());
    }
});

Event::listen(function (DiagnosingHealth $event) {
    $last = Cache::get('anthropic:last-success');

    if ($last !== null && now()->getTimestamp() - $last > 900) {
        throw new RuntimeException('No Claude call has gone through in 15 minutes.');
    }
});
```

This suits an app that calls Claude all day. One that calls it now and then should check only its config here, and leave the API to `anthropic:doctor`.

---

For the client's settings these commands read, see the [Configuration page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/reference/configuration#reading-the-settings-back), or the [Anthropic rate limits](https://platform.claude.com/docs/en/api/rate-limits) for what each call counts against.
