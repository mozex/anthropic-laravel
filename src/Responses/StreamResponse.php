<?php

declare(strict_types=1);

namespace Anthropic\Laravel\Responses;

use Anthropic\Exceptions\ErrorException;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Responses\Messages\StreamResponse as MessagesStreamResponse;
use ArrayObject;
use Generator;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A Messages stream with Laravel responses over it: `toEventStream()` for `useEventStream`, `toTextStream()` for
 * `useStream` and `toVercelStream()` for the AI SDK's `useChat`. Every stream `Anthropic::messages()->createStreamed()`
 * returns is one, a fake's included (an injected `ClientContract`'s isn't: `as()` makes one). Each reports a failure,
 * and stops reading when the browser goes away where PHP can tell: under PHP-FPM, not under Octane's Swoole or
 * RoadRunner.
 *
 * @extends MessagesStreamResponse<CreateStreamedResponse>
 */
class StreamResponse extends MessagesStreamResponse
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * The seconds without a write after which an event stream sends a comment, on the next event it doesn't pass on (a
     * thinking delta, a tool's input, a server tool's block), so a proxy's idle timeout (nginx's 60, a CDN's 100)
     * doesn't close it. While nothing arrives at all, as while Claude thinks with its thinking omitted, nothing can be
     * sent.
     */
    private const KEEP_ALIVE = 15;

    /**
     * What is reported, and the browser told, of a stream that ended before its message did, without an error.
     */
    private const CUT_OFF = 'The stream ended before the message did.';

    private int $lastSent = 0;

    /**
     * The text as server-sent events, each delta an `update` event with every line on its own `data:` line, so its
     * newlines arrive intact. A failure, or a stream that ends before the message does, is reported and goes out as an
     * `error` event, and the stream ends with an `update` of `$endStreamWith` (unless it's empty), so `useEventStream`
     * closes it rather than reconnecting, which would run the route and bill the call again. A comment goes out after
     * 15 quiet seconds.
     */
    public function toEventStream(string $endStreamWith = '</stream>'): StreamedResponse
    {
        return new StreamedResponse(function () use ($endStreamWith): void {
            $this->lastSent = Date::now()->getTimestamp();

            try {
                foreach ($this as $event) {
                    if (connection_aborted()) {
                        return;
                    }

                    if ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta') {
                        $this->send(self::event('update', (string) $event->delta->text));
                    } else {
                        $this->keepAlive();
                    }
                }

                if (! $this->completed()) {
                    report(new RuntimeException(self::CUT_OFF));
                    $this->send(self::event('error', json_encode(['type' => 'error', 'message' => self::CUT_OFF], self::JSON)));
                }
            } catch (Throwable $e) {
                report($e);
                $this->send(self::event('error', json_encode(self::error($e), self::JSON)));
            }

            if ($endStreamWith !== '') {
                $this->send(self::event('update', $endStreamWith));
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    /**
     * The text as a plain stream of its deltas.
     */
    public function toTextStream(): StreamedResponse
    {
        return new StreamedResponse(function (): void {
            try {
                foreach ($this->text() as $delta) {
                    if (connection_aborted()) {
                        return;
                    }

                    $this->send($delta);
                }
            } catch (Throwable $e) {
                // A plain text stream has no way to say it failed: it ends.
                report($e);
            }
        }, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    /**
     * The message as the AI SDK's UI message stream (v1): text, reasoning, the app's own tool calls and web search
     * sources (each URL once) as their parts, ending in `data: [DONE]`, after an `error` part when it fails or ends
     * before the message does. Server tools run on Anthropic's side, so their calls and results aren't sent. A
     * comment goes out after 15 quiet seconds.
     */
    public function toVercelStream(?string $messageId = null): StreamedResponse
    {
        return new StreamedResponse(function () use ($messageId): void {
            $this->lastSent = Date::now()->getTimestamp();

            try {
                foreach ($this->vercelParts($messageId) as $parts) {
                    if (connection_aborted()) {
                        return;
                    }

                    if ($parts === []) {
                        $this->keepAlive();
                    }

                    foreach ($parts as $part) {
                        $this->send(self::part($part));
                    }
                }

                if (! $this->completed()) {
                    report(new RuntimeException(self::CUT_OFF));
                    $this->send(self::part(['type' => 'error', 'errorText' => self::CUT_OFF]));
                }
            } catch (Throwable $e) {
                report($e);
                $this->send(self::part(['type' => 'error', 'errorText' => self::error($e)['message']]));
            }

            $this->send("data: [DONE]\n\n");
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }

    /**
     * Each event's parts, an empty list for one with none; the finish parts once the message ends.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function vercelParts(?string $messageId): Generator
    {
        // The open blocks' parts, by index: [the part kind, its id, for a tool call its name, and for reasoning whether
        // its start was sent].
        $open = [];
        $sources = [];

        foreach ($this as $event) {
            yield iterator_to_array($this->partsOf($event, $messageId, $open, $sources), false);
        }

        if ($this->completed()) {
            yield [['type' => 'finish-step'], ['type' => 'finish']];
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3?: bool}|null>  $open
     * @param  array<string, true>  $sources
     * @return Generator<int, array<string, mixed>>
     */
    private function partsOf(CreateStreamedResponse $event, ?string $messageId, array &$open, array &$sources): Generator
    {
        $index = $event->index ?? -1;

        if ($event->type === 'message_start') {
            yield ['type' => 'start', 'messageId' => $messageId ?? $event->message->id];
            yield ['type' => 'start-step'];
        } elseif ($event->type === 'content_block_start') {
            $block = $event->content_block_start;

            $open[$index] = match ($block->type) {
                'text' => ['text', "text-{$index}", null],
                // Opened on its first delta with text: omitted thinking streams none.
                'thinking' => ['reasoning', "reasoning-{$index}", null, false],
                'tool_use' => ['tool-input', (string) $block->id, (string) $block->name],
                default => null,
            };

            [$kind, $id, $name] = $open[$index] ?? [null, null, null];

            if ($kind === 'text') {
                yield ['type' => 'text-start', 'id' => $id];
            } elseif ($kind === 'tool-input') {
                yield ['type' => 'tool-input-start', 'toolCallId' => $id, 'toolName' => $name];
            }
        } elseif ($event->type === 'content_block_delta' && isset($open[$index])) {
            [$kind, $id] = $open[$index];
            $delta = $event->delta;

            if ($delta->type === 'text_delta' && (string) $delta->text !== '') {
                yield ['type' => 'text-delta', 'id' => $id, 'delta' => (string) $delta->text];
            } elseif ($delta->type === 'thinking_delta' && (string) $delta->thinking !== '') {
                if (($open[$index][3] ?? true) === false) {
                    $open[$index][3] = true;

                    yield ['type' => 'reasoning-start', 'id' => $id];
                }

                yield ['type' => 'reasoning-delta', 'id' => $id, 'delta' => (string) $delta->thinking];
            } elseif ($delta->type === 'input_json_delta' && $kind === 'tool-input' && (string) $delta->partial_json !== '') {
                yield ['type' => 'tool-input-delta', 'toolCallId' => $id, 'inputTextDelta' => $delta->partial_json];
            } elseif ($delta->type === 'citations_delta' && is_string($delta->citation['url'] ?? null)) {
                $url = $delta->citation['url'];

                if (($delta->citation['type'] ?? null) === 'web_search_result_location' && ! isset($sources[$url])) {
                    yield [
                        'type' => 'source-url',
                        'sourceId' => $url,
                        'url' => $url,
                        ...(is_string($delta->citation['title'] ?? null) ? ['title' => $delta->citation['title']] : []),
                    ];
                }

                $sources[$url] = true;
            }
        } elseif ($event->type === 'content_block_stop' && isset($open[$index])) {
            [$kind, $id, $name] = $open[$index];
            $started = $open[$index][3] ?? true;
            unset($open[$index]);

            if ($kind === 'tool-input') {
                yield ['type' => 'tool-input-available', 'toolCallId' => $id, 'toolName' => $name, 'input' => ($this->currentMessage()?->content[$index]->input ?? []) ?: new ArrayObject];
            } elseif ($started) {
                yield ['type' => "{$kind}-end", 'id' => $id];
            }
        }
    }

    /**
     * An event's frame: every line of its data on its own `data:` line.
     */
    private static function event(string $event, string $data): string
    {
        return "event: {$event}\ndata: ".preg_replace('/\r\n|\r|\n/', "\ndata: ", $data)."\n\n";
    }

    /**
     * A UI message stream part's frame.
     *
     * @param  array<string, mixed>  $part
     */
    private static function part(array $part): string
    {
        return 'data: '.json_encode($part, self::JSON)."\n\n";
    }

    /**
     * A comment, which `EventSource` and the AI SDK skip, once the stream has been quiet for a while.
     */
    private function keepAlive(): void
    {
        if (Date::now()->getTimestamp() - $this->lastSent >= self::KEEP_ALIVE) {
            $this->send(": keep-alive\n\n");
        }
    }

    private function send(string $output): void
    {
        $this->lastSent = Date::now()->getTimestamp();

        echo $output;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * What the browser is told of a failure: the API's error type and message, never another exception's message.
     *
     * @return array{type: string, message: string}
     */
    private static function error(Throwable $e): array
    {
        return $e instanceof ErrorException
            ? ['type' => $e->getErrorType() ?? 'error', 'message' => $e->getErrorMessage()]
            : ['type' => 'error', 'message' => 'The stream failed.'];
    }
}
