---
title: Token Budgets
weight: 13
---

A token budget caps how many tokens a user, a team or anything else you key it by can spend in a window: 200,000 tokens a day per user, say. Calls under a used-up budget are refused before they're sent, so they cost nothing.

## Enforcing a budget

```php
use Anthropic\Laravel\Facades\Anthropic;

Anthropic::budget('user:'.$request->user()->id)->perDay(200_000)->enforce();

$reply = Anthropic::messages()->create([...]);
```

After `enforce()`, every Messages call and batch in the request or job, and the jobs it dispatches, is charged as it's billed. Once the budget is used up, the next one is refused with `Anthropic\Laravel\Exceptions\BudgetExceeded`. A stream is charged when it ends, and a batch when its poll job reads the results. Token counts go through and cost nothing. `perMinute()`, `perHour()` and `perDay()` set the window.

You can enforce more than one budget: a user's per-minute and per-day budgets, or a user's and their team's. Each call is charged to all of them and refused once any one is used up. Enforcing the same key and window again replaces the earlier one.

In a loop over users, say in a command or a scheduled task, `enforce()` would add each user's budget to the last. `during()` holds only the calls its callback makes, then puts back what was enforced before:

```php
foreach ($users as $user) {
    Anthropic::budget('user:'.$user->id)->perDay(10_000)->during(
        fn () => Anthropic::messages()->create([...]),
    );
}
```

A stream is charged when it ends, so read it inside the callback.

By default a budget counts every token billed: input, cache writes, cache reads and output, across `usage.iterations` (compaction, the advisor). `->counting('rate')` counts what rate limits count instead, which leaves cache reads out. Either way it counts tokens, not cost: cache reads are billed at a fraction of the input price, and the advisor's tokens at the advisor model's rates.

The budget lives on Laravel's rate limiter, in your cache store, so servers and workers that share that store share the budget. If the store is down, a budgeted call fails rather than going through unchecked. The windows are fixed: a day's budget resets a day after its first charge. A call is charged once it's billed, so the calls already in flight when the budget runs out still go through, and only the ones after them are refused.

`BudgetExceeded` renders as a 429 with `Retry-After`, the same as Laravel's own throttle, and isn't reported. Its message leaves the key out, because a JSON error answer shows the message. Your code can read the key from `$e->key`.

## Checking it yourself

```php
$budget = Anthropic::budget('team:'.$team->id)->perDay(1_000_000);

$budget->remaining();   // 734_120
$budget->exceeded();    // false
$budget->availableIn(); // seconds until the window resets
$budget->charge($response->usage); // for usage you record another way
$budget->clear();
```

## On a route

The `anthropic.budget` middleware enforces a budget for each request. Once the budget is used up, it answers 429 with `Retry-After`, either at the door or partway through the request:

```php
Route::post('/chat', ChatController::class)->middleware('anthropic.budget:user,200000,day');
```

Its arguments are the key, the tokens (a plain number, without the `_`), the window (`minute`, `hour` or `day`) and the count (`billed` or `rate`). `user` keys it by the signed-in user's ID, or by IP for a guest, `ip` by IP, and any other key is used as it is. Behind a load balancer, set up Laravel's trusted proxies, or every guest shares the proxy's IP and its budget.

## On a queued job

```php
use Anthropic\Laravel\Queue\TokenBudget;

public function middleware(): array
{
    return [new TokenBudget(Anthropic::budget('team:'.$this->team->id)->perDay(1_000_000))];
}

// each release counts as an attempt, so bound the job by time, past the window
public function retryUntil(): DateTime
{
    return now()->addDays(2);
}
```

A job is held to its own budget and to any budget enforced where it was dispatched. When one is used up, the job is released until that budget resets. A long wait is split into releases of six hours at most, well inside the 12 hours SQS holds a job. Running out partway releases the job too, and it starts again from the top next time, so the calls it already made are made and billed again. On the `sync` queue, or run directly, the job throws `BudgetExceeded`, since nothing would run it again. Once the job ends, the caller's own budgets are put back.

A Claude job dispatched under a budget gets that budget too, with or without the middleware. Without it, a refusal fails the job, and since `BudgetExceeded` isn't reported, nothing is logged. Give such a job `new TokenBudget()` with no budget of its own, and it's released for the budgets it inherited.

## In tests

`Anthropic::fake()` sends nothing, so its calls are neither charged nor refused. An `Anthropic::batch()` poll job still charges its results. To test a refusal, use up the budget yourself, then check the route's 429 or the job's release:

```php
Anthropic::budget('user:'.$user->id)->perDay(200_000)->charge(['input_tokens' => 200_000, 'output_tokens' => 0]);

$this->actingAs($user)->post('/chat')->assertStatus(429);
```

Several requests in one test share their Context, so the budget the first request's middleware enforced still holds the second request's calls.

---

For the counts a budget charges, see the [Messages page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/messages#token-usage), and for the API's own limits, Anthropic's [rate limits page](https://platform.claude.com/docs/en/api/rate-limits).
