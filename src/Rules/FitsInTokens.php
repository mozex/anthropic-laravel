<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Rules;

use Anthropic\Exceptions\RequestTooLargeException;
use Anthropic\Laravel\AnthropicManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A text that, sent as one user message to `$model`, counts at most `$limit` input tokens: `new FitsInTokens(4000,
 * 'claude-sonnet-5-5')`. It counts with the API (free, but rate limited), so put it last, behind `bail`. A text too
 * large for the API to count fails; any other failed count throws, since it isn't the input's fault.
 */
class FitsInTokens implements ValidationRule
{
    private ?string $system = null;

    private ?string $connection = null;

    public function __construct(
        private readonly int $limit,
        private readonly string $model,
    ) {}

    /**
     * Counts the text with this system prompt, as it'll be sent.
     */
    public function withSystem(string $system): self
    {
        $this->system = $system;

        return $this;
    }

    /**
     * Counts through this Anthropic connection.
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The API rejects a text of only whitespace, which is the other rules' to judge.
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $tokens = app(AnthropicManager::class)->connection($this->connection)->messages()->countTokens([
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $value]],
                ...($this->system === null ? [] : ['system' => $this->system]),
            ])->inputTokens;
        } catch (RequestTooLargeException) {
            $fail("The :attribute is too long to count, over the limit of {$this->limit} tokens.");

            return;
        }

        if ($tokens > $this->limit) {
            $fail("The :attribute is {$tokens} tokens long, over the limit of {$this->limit}.");
        }
    }
}
