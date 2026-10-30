<?php

declare(strict_types=1);

use Anthropic\Exceptions\OverloadedException;
use Anthropic\Laravel\Events\UsageRecorded;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Livewire\InteractsWithClaudeStreams;
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Responses\Messages\StreamResponse;
use Anthropic\Testing\Blocks;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A stream whose text arrives in these deltas, and anything after them in place of the end.
 *
 * @param  list<string>  $deltas
 * @param  list<array<string, mixed>>|null  $end
 */
function deltasStream(array $deltas, ?array $end = null): StreamResponse
{
    // composed: the docs' message_start, with these deltas in one text block
    return CreateStreamedResponse::fake([
        ['type' => 'message_start', 'message' => ['id' => 'msg_014p7gG3wDgGV9EUtLvnow3U', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 472, 'output_tokens' => 2]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ...array_map(fn (string $text): array => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]], $deltas),
        ...($end ?? [
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 89]],
            ['type' => 'message_stop'],
        ]),
    ]);
}

/**
 * The broadcasts sent, as [name, payload].
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function broadcasts(): array
{
    return Event::dispatched(AnonymousEvent::class)->map(fn (array $event): array => [$event[0]->broadcastAs(), $event[0]->broadcastWith()])->values()->all();
}

test('broadcastTo() sends the deltas together, then the end, and returns the message', function () {
    $this->freezeTime();
    Event::fake([AnonymousEvent::class]);
    Anthropic::fake([deltasStream(['Okay', ',', ' let', "'s"])]);

    $message = Anthropic::messages()->createStreamed([])->broadcastTo(new PrivateChannel('chat.1'));

    expect(broadcasts())->toBe([
        ['claude.delta', ['text' => "Okay, let's", 'sequence' => 0]],
        ['claude.done', ['id' => 'msg_014p7gG3wDgGV9EUtLvnow3U', 'stop_reason' => 'end_turn']],
    ])
        ->and(Event::dispatched(AnonymousEvent::class)->first()[0]->broadcastOn())->toEqual([new PrivateChannel('chat.1')])
        ->and(Event::dispatched(AnonymousEvent::class)->first()[0]->shouldBroadcastNow())->toBeTrue()
        ->and($message->content[0]->text)->toBe("Okay, let's");
});

test('broadcastTo() sends what came once its interval has passed', function () {
    $this->freezeTime();
    Event::fake([AnonymousEvent::class]);
    Anthropic::fake([deltasStream(['Okay', ',', ' let', "'s"])]);

    Anthropic::messages()->createStreamed([])
        ->onText(fn (string $delta) => $delta === ',' ? $this->travel(60)->milliseconds() : null)
        ->broadcastTo('chat.1');

    // the end event carries no text
    expect(array_column(array_column(broadcasts(), 1), 'text'))->toBe(['Okay,', " let's"]);
});

test('broadcastTo() keeps each payload under its size as Reverb receives it, cut between characters', function () {
    $this->freezeTime();
    Event::fake([AnonymousEvent::class]);
    // the docs' thinking delta, many times over: × is two bytes, and escaped in JSON
    $text = str_repeat('1071 = 2 × 462 + 147', 20);
    Anthropic::fake([deltasStream([$text])]);

    Anthropic::messages()->createStreamed([])->broadcastTo('chat.1', maxBytes: 256);

    $deltas = array_values(array_filter(broadcasts(), fn (array $broadcast): bool => $broadcast[0] === 'claude.delta'));

    expect(implode('', array_map(fn (array $broadcast): string => $broadcast[1]['text'], $deltas)))->toBe($text)
        ->and($deltas)->toHaveCount(3)
        ->and(max(array_map(fn (array $broadcast): int => strlen(json_encode(json_encode($broadcast[1]))), $deltas)))->toBeLessThanOrEqual(256)
        ->and(array_map(fn (array $broadcast): bool => mb_check_encoding($broadcast[1]['text'], 'UTF-8'), $deltas))->not->toContain(false)
        ->and(array_column(array_column($deltas, 1), 'sequence'))->toBe(range(0, count($deltas) - 1));
});

/**
 * Livewire 3's `stream()`: `stream($to, $content, $replace = false)`.
 */
class LivewireThreeComponent
{
    use InteractsWithClaudeStreams;

    /** @var list<array{0: string, 1: string, 2: bool}> */
    public array $streamed = [];

    public function stream(string $to, string $content, bool $replace = false): void
    {
        $this->streamed[] = [$to, $content, $replace];
    }

    public function ask(StreamResponse $stream, bool $replace = false): string
    {
        return $this->streamClaude($stream, to: 'answer', replace: $replace);
    }
}

/**
 * Livewire 4's `stream()`: `stream($content = null, $replace = false, $name = null, $el = null, $ref = null, $to = null)`.
 */
class LivewireFourComponent
{
    use InteractsWithClaudeStreams;

    /** @var list<array{0: ?string, 1: ?string, 2: bool}> */
    public array $streamed = [];

    public function stream(?string $content = null, bool $replace = false, ?string $name = null, ?string $el = null, ?string $ref = null, ?string $to = null): void
    {
        $this->streamed[] = [$to, $content, $replace];
    }

    public function ask(StreamResponse $stream, bool $replace = false): string
    {
        return $this->streamClaude($stream, to: 'answer', replace: $replace);
    }
}

test('the Livewire trait streams each delta into its target on Livewire 3 and 4, and returns the text', function (string $component) {
    $livewire = new $component;

    // the first update replaces what the element showed
    expect($livewire->ask(deltasStream(['Okay', ',', ' let', "'s"])))->toBe("Okay, let's")
        ->and($livewire->streamed)->toBe([['answer', 'Okay', true], ['answer', ',', false], ['answer', ' let', false], ['answer', "'s", false]]);

    $livewire->streamed = [];
    $livewire->ask(deltasStream(['Okay', ',']), replace: true);

    expect($livewire->streamed)->toBe([['answer', 'Okay', true], ['answer', 'Okay,', true]]);
})->with([LivewireThreeComponent::class, LivewireFourComponent::class]);

test('a refused stream falls back through the configured models, and its usage is recorded once for both', function () {
    Event::fake([UsageRecorded::class]);
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true, 'anthropic.fallback_models' => ['claude-opus-4-8']]);
    $sse = fn (array $events): string => implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
    // composed: from the official SDK's refusal fallback fixtures, the refused answer cut to its text
    $refused = $sse([
        ['type' => 'message_start', 'message' => ['model' => 'claude-fable-5', 'id' => 'msg_fixture_a_0001', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 28, 'output_tokens' => 5]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'refusal', 'stop_sequence' => null, 'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null, 'fallback_credit_token' => 'tok_synthetic_fixture_a', 'fallback_has_prefill_claim' => true]], 'usage' => ['output_tokens' => 106, 'iterations' => [['type' => 'message', 'input_tokens' => 28, 'output_tokens' => 106]]]],
        ['type' => 'message_stop'],
    ]);
    $served = $sse([
        ['type' => 'message_start', 'message' => ['model' => 'claude-opus-4-8', 'id' => 'msg_fixture_b_0001', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 31, 'output_tokens' => 7]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' there! Happy to expl']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 547, 'iterations' => [['type' => 'message', 'input_tokens' => 31, 'output_tokens' => 547]]]],
        ['type' => 'message_stop'],
    ]);
    Http::fakeSequence()->push($refused, 200, ['Content-Type' => 'text/event-stream'])->push($served, 200, ['Content-Type' => 'text/event-stream']);

    $message = Anthropic::messages()->createStreamed(['model' => 'claude-fable-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello, Claude']]])->finalMessage();

    expect(array_column($message->toArray()['content'], 'type'))->toBe(['text', 'fallback', 'text'])
        ->and($message->model)->toBe('claude-opus-4-8');

    Http::assertSentCount(2);
    Event::assertDispatchedTimes(UsageRecorded::class, 1);
    Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $usage): bool => CreateResponse::fake(['usage' => $usage->usage])->usage->billedTokens() === 712);
});

test('broadcastTo() works on a stream built for a fake response too', function () {
    Event::fake([AnonymousEvent::class]);
    Anthropic::fake([CreateStreamedResponse::fake(CreateResponse::fake(['content' => [Blocks::text('Hi! How can I help you today?')]]))]);

    Anthropic::messages()->createStreamed([])->broadcastTo('chat.1');

    expect(broadcasts()[0])->toBe(['claude.delta', ['text' => 'Hi! How can I help you today?', 'sequence' => 0]]);
});

test('broadcastTo() sends the text before a tool call at once, and says when a fallback takes over', function () {
    $this->freezeTime();
    Event::fake([AnonymousEvent::class]);
    Anthropic::fake([CreateStreamedResponse::fake(CreateResponse::fake(['content' => [
        Blocks::text("Okay, let's check the weather for San Francisco, CA:"),
        Blocks::toolUse('get_weather', ['location' => 'San Francisco, CA']),
        // composed: a fallback block as a spliced stream carries it
        ['type' => 'fallback', 'from' => ['model' => 'claude-fable-5'], 'to' => ['model' => 'claude-opus-4-8'], 'trigger' => ['type' => 'refusal', 'category' => 'cyber']],
        Blocks::text('Hi! How can I help you today?'),
    ]]))]);

    Anthropic::messages()->createStreamed([])->broadcastTo('chat.1');

    expect(array_column(broadcasts(), 0))->toBe(['claude.delta', 'claude.fallback', 'claude.delta', 'claude.done'])
        ->and(broadcasts()[1][1])->toBe(['from' => 'claude-fable-5', 'to' => 'claude-opus-4-8']);
});

test('broadcastTo() sends an error when the stream fails or is cut off, and throws on', function () {
    Event::fake([AnonymousEvent::class]);
    // the streaming page's error event
    Anthropic::fake([
        deltasStream(['Okay'], [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]),
        deltasStream(['Okay'], []),
    ]);

    expect(fn () => Anthropic::messages()->createStreamed([])->broadcastTo('chat.1'))->toThrow(OverloadedException::class)
        ->and(broadcasts()[1])->toBe(['claude.error', ['type' => 'overloaded_error', 'message' => 'Overloaded']])
        ->and(fn () => Anthropic::messages()->createStreamed([])->broadcastTo('chat.1'))->toThrow(RuntimeException::class, 'ended before the message did')
        ->and(array_column(broadcasts(), 0))->toBe(['claude.delta', 'claude.error', 'claude.delta', 'claude.error']);
});

test('a broadcaster that fails is reported, its deltas skipped after, and the message is still read and returned', function () {
    Exceptions::fake();
    Broadcast::extend('failing', fn () => new class implements Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Pusher error: 413 Payload Too Large.');
        }
    });
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    Anthropic::fake([deltasStream(['Okay', ',', ' let', "'s"])]);

    $message = Anthropic::messages()->createStreamed([])->broadcastTo('chat.1', everyMs: 0);

    expect($message->content[0]->text)->toBe("Okay, let's");
    // the first delta, then the closing event, which is still tried
    Exceptions::assertReportedCount(2);
});

test('broadcastTo() takes a sensible interval and size', function () {
    Anthropic::fake([deltasStream(['Okay']), deltasStream(['Okay'])]);

    expect(fn () => Anthropic::messages()->createStreamed([])->broadcastTo('chat.1', maxBytes: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Anthropic::messages()->createStreamed([])->broadcastTo('chat.1', everyMs: -1))->toThrow(InvalidArgumentException::class);
});
