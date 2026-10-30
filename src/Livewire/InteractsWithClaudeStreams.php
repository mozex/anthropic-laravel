<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Livewire;

use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Responses\Messages\StreamResponse;

/**
 * For a Livewire component: `$this->streamClaude($stream, to: 'answer')` streams the text into the element with
 * `wire:stream="answer"` as it arrives, and returns the final text to save. It calls Livewire's `stream()` with named
 * arguments only, the one form Livewire 3 and 4 share. Livewire's streaming doesn't run under Octane, and it holds a
 * PHP-FPM worker for the whole stream.
 */
trait InteractsWithClaudeStreams
{
    /**
     * The first update replaces what the element showed, so a second answer doesn't land on the first. With `$replace`,
     * every update replaces it with the text so far, rather than adding the delta.
     *
     * @param  StreamResponse<CreateStreamedResponse>  $stream
     */
    protected function streamClaude(StreamResponse $stream, string $to, bool $replace = false): string
    {
        $text = '';

        foreach ($stream->text() as $delta) {
            $first = $text === '';
            $text .= $delta;

            $this->stream(to: $to, content: $replace ? $text : $delta, replace: $replace || $first);
        }

        return $stream->finalText();
    }
}
