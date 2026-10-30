## mozex/anthropic-laravel

Claude through the `Anthropic` facade (`Anthropic\Laravel\Facades\Anthropic`). The `anthropic-laravel` skill has the details; these are the rules that bite.

- Type-hint `Anthropic\Contracts\ClientContract`, never `Anthropic\Client`, so `Anthropic::fake()` reaches injected code. In tests, `Anthropic::fake([CreateResponse::fake([...])])` then `Anthropic::assertSent(Messages::class, ...)`: nothing reaches the API.
- Casing follows the wire format: `$response->stop_reason`, but `$response->usage->inputTokens`. Check the DTO rather than guess.
- A refusal is HTTP 200 with `stop_reason === 'refusal'`, and `stop_reason === 'pause_turn'` means echo the content back and call again. Neither throws, so branch on `stop_reason`.
- Dispatch `tool_use` blocks on the (`toolset_name`, `name`) pair, never on `name` alone, and run a block whose `caller` names code execution too.
- Branch on `$e->getErrorType()` or the exception class, not the status code: an error in the middle of a stream arrives with status 200, so wrap the stream's loop in a `try`.
- Long calls belong in a queued job, with the queue connection's `retry_after` above `request_timeout` × (`max_retries` + 1) × (1 + the fallback models), or a second worker runs the job and the call is paid twice. A streamed call's timeout is only the gap between chunks, so size a streaming job's `$timeout` and `retry_after` for the whole answer. `php artisan anthropic:doctor` checks the non-streamed case.
