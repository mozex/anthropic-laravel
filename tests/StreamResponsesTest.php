<?php

declare(strict_types=1);

use Anthropic\Exceptions\OverloadedException;
use Anthropic\Laravel\Events\UsageRecorded;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Responses\Messages\CountTokensResponse;
use Anthropic\Responses\Messages\CreateResponse;
use Anthropic\Responses\Messages\CreateStreamedResponse;
use Anthropic\Testing\Blocks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A stream's events with these text deltas, and anything after them in place of the end.
 *
 * @param  list<string>  $deltas
 * @param  list<array<string, mixed>>|null  $end
 * @return list<array<string, mixed>>
 */
function textStreamEvents(array $deltas, ?array $end = null): array
{
    // composed: the docs' message_start, with these deltas in one text block
    return [
        ['type' => 'message_start', 'message' => ['id' => 'msg_014p7gG3wDgGV9EUtLvnow3U', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 472, 'output_tokens' => 2]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ...array_map(fn (string $text): array => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]], $deltas),
        ...($end ?? [
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 89]],
            ['type' => 'message_stop'],
        ]),
    ];
}

/**
 * A `citations_delta` on the text block of the web search page's citation, as the PHP client's fixture has it.
 *
 * @return array<string, mixed>
 */
function streamCitationDelta(): array
{
    $citation = ['type' => 'web_search_result_location', 'url' => 'https://en.wikipedia.org/wiki/Claude_Shannon', 'title' => 'Claude Shannon - Wikipedia', 'encrypted_index' => 'Eo8BCioIAhgBIiQyYjQ0OWJmZi1lNm', 'cited_text' => 'Claude Elwood Shannon (April 30, 1916 – February 24, 2001) was an American mathematician'];

    return ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => $citation]];
}

/**
 * A route that streams the queued fake through one of the responses.
 */
function streamRoute(string $response): string
{
    Route::get('/stream', fn () => Anthropic::messages()->createStreamed(['model' => 'claude-opus-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello!']]])->{$response}());

    return '/stream';
}

test('toEventStream() sends each delta as an update with every line its own data line, then the end marker', function () {
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello', "\n\n", 'world']))]);

    $response = $this->get(streamRoute('toEventStream'));

    $response->assertOk()->assertHeader('X-Accel-Buffering', 'no');

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->streamedContent())->toBe(
            "event: update\ndata: Hello\n\n"
            ."event: update\ndata: \ndata: \ndata: \n\n"
            ."event: update\ndata: world\n\n"
            ."event: update\ndata: </stream>\n\n",
        );
});

test('after a mid-stream error toEventStream() reports it, sends an error event, then the end marker', function () {
    Exceptions::fake();
    // the streaming page's error event
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello'], [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]))]);

    expect($this->get(streamRoute('toEventStream'))->streamedContent())->toBe(
        "event: update\ndata: Hello\n\n"
        ."event: error\ndata: {\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}\n\n"
        ."event: update\ndata: </stream>\n\n",
    );

    Exceptions::assertReported(OverloadedException::class);
});

test('a failure of the app own code is sent without its message', function () {
    Exceptions::fake();
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello']))]);
    Route::get('/stream', fn () => Anthropic::messages()->createStreamed([])
        ->onText(fn () => throw new RuntimeException('SQLSTATE: a detail the browser must not see'))
        ->toEventStream());

    expect($this->get('/stream')->streamedContent())->toBe(
        "event: error\ndata: {\"type\":\"error\",\"message\":\"The stream failed.\"}\n\n"
        ."event: update\ndata: </stream>\n\n",
    );

    Exceptions::assertReported(RuntimeException::class);
});

test('toTextStream() sends the text as it is', function () {
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello', "\n\n", 'world']))]);

    $response = $this->get(streamRoute('toTextStream'));

    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    expect($response->streamedContent())->toBe("Hello\n\nworld");
});

test('toVercelStream() sends the UI message stream parts under its header, ending in [DONE]', function () {
    Anthropic::fake([CreateStreamedResponse::fake(CreateResponse::fake([
        'content' => [
            Blocks::thinking('I need to find the GCD of 1071 and 462 using the Euclidean algorithm.'),
            Blocks::text("Okay, let's check the weather for San Francisco, CA:"),
            Blocks::toolUse('get_weather', ['location' => 'San Francisco, CA']),
            Blocks::serverToolUse(),
        ],
        'stop_reason' => 'tool_use',
    ]))]);

    $response = $this->get(streamRoute('toVercelStream'));

    $response->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

    $id = CreateResponse::fake()->id;

    expect(explode("\n\n", trim($response->streamedContent())))->toBe([
        'data: {"type":"start","messageId":"'.$id.'"}',
        'data: {"type":"start-step"}',
        'data: {"type":"reasoning-start","id":"reasoning-0"}',
        'data: {"type":"reasoning-delta","id":"reasoning-0","delta":"I need to find the GCD of 1071 and 462 using the Euclidean algorithm."}',
        'data: {"type":"reasoning-end","id":"reasoning-0"}',
        'data: {"type":"text-start","id":"text-1"}',
        'data: {"type":"text-delta","id":"text-1","delta":"Okay, let\'s check the weather for San Francisco, CA:"}',
        'data: {"type":"text-end","id":"text-1"}',
        'data: {"type":"tool-input-start","toolCallId":"toolu_01T1x1fJ34qAmk2tNTrN7Up6","toolName":"get_weather"}',
        'data: {"type":"tool-input-delta","toolCallId":"toolu_01T1x1fJ34qAmk2tNTrN7Up6","inputTextDelta":"{\"location\":\"San Francisco, CA\"}"}',
        'data: {"type":"tool-input-available","toolCallId":"toolu_01T1x1fJ34qAmk2tNTrN7Up6","toolName":"get_weather","input":{"location":"San Francisco, CA"}}',
        // the server tool runs on Anthropic's side, so it isn't sent
        'data: {"type":"finish-step"}',
        'data: {"type":"finish"}',
        'data: [DONE]',
    ]);
});

test('toVercelStream() sends a web search citation as a source, and an error as its part before [DONE]', function () {
    Exceptions::fake();
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Claude Shannon was born on April 30, 1916, in Petoskey, Michigan'], [
        streamCitationDelta(),
        ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
    ]))]);

    $parts = explode("\n\n", trim($this->get(streamRoute('toVercelStream'))->streamedContent()));

    expect(array_slice($parts, -3))->toBe([
        'data: {"type":"source-url","sourceId":"https://en.wikipedia.org/wiki/Claude_Shannon","url":"https://en.wikipedia.org/wiki/Claude_Shannon","title":"Claude Shannon - Wikipedia"}',
        'data: {"type":"error","errorText":"Overloaded"}',
        'data: [DONE]',
    ]);
});

test('a real stream gets the responses too, and its usage is recorded once', function () {
    Event::fake([UsageRecorded::class]);
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
    $body = implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", textStreamEvents(['Hello'])));
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

    expect($this->get(streamRoute('toEventStream'))->streamedContent())->toBe("event: update\ndata: Hello\n\nevent: update\ndata: </stream>\n\n");

    Event::assertDispatchedTimes(UsageRecorded::class, 1);
});

test('a quiet stretch gets a keep-alive comment, so a proxy does not close the stream', function () {
    Anthropic::fake([CreateStreamedResponse::fake(CreateResponse::fake(['content' => [
        Blocks::thinking('I need to find the GCD of 1071 and 462 using the Euclidean algorithm.'),
        Blocks::text('The greatest common divisor of 1071 and 462 is **21**.'),
    ]]))]);
    Route::get('/stream', fn () => Anthropic::messages()->createStreamed([])
        // Claude thinks for 20 seconds
        ->onThinking(fn () => $this->travel(20)->seconds())
        ->toEventStream());

    expect($this->get('/stream')->streamedContent())->toBe(
        ": keep-alive\n\n"
        ."event: update\ndata: The greatest common divisor of 1071 and 462 is **21**.\n\n"
        ."event: update\ndata: </stream>\n\n",
    );
});

test('a stream cut off upstream ends in an error part and [DONE], without the finish parts', function () {
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello'], []))]);

    $parts = explode("\n\n", trim($this->get(streamRoute('toVercelStream'))->streamedContent()));

    expect(array_slice($parts, -2))->toBe(['data: {"type":"error","errorText":"The stream ended before the message did."}', 'data: [DONE]'])
        ->and($parts)->not->toContain('data: {"type":"finish"}');
});

test('a stream cut off upstream ends in an error event and the marker', function () {
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello'], []))]);

    expect($this->get(streamRoute('toEventStream'))->streamedContent())->toBe(
        "event: update\ndata: Hello\n\n"
        ."event: error\ndata: {\"type\":\"error\",\"message\":\"The stream ended before the message did.\"}\n\n"
        ."event: update\ndata: </stream>\n\n",
    );
});

test('an empty end marker is not sent, and carriage returns split lines too', function () {
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(["Hello\r\nworld"]))]);
    Route::get('/stream', fn () => Anthropic::messages()->createStreamed([])->toEventStream(''));

    expect($this->get('/stream')->streamedContent())->toBe("event: update\ndata: Hello\ndata: world\n\n");
});

test('toTextStream() reports a failure and just ends', function () {
    Exceptions::fake();
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello'], [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]))]);

    expect($this->get(streamRoute('toTextStream'))->streamedContent())->toBe('Hello');

    Exceptions::assertReported(OverloadedException::class);
});

test('toVercelStream() takes a message ID, and lists a cited page once', function () {
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Claude Shannon was born on April 30, 1916, in Petoskey, Michigan'], [streamCitationDelta(), streamCitationDelta(), ['type' => 'message_stop']]))]);
    Route::get('/stream', fn () => Anthropic::messages()->createStreamed([])->toVercelStream('chat-message-1'));

    $parts = explode("\n\n", trim($this->get('/stream')->streamedContent()));

    expect($parts[0])->toBe('data: {"type":"start","messageId":"chat-message-1"}')
        ->and(count(array_filter($parts, fn (string $part): bool => str_contains($part, '"source-url"'))))->toBe(1);
});

test('the Messages resource passes every other call on, a fake assertion included', function () {
    Anthropic::fake([CountTokensResponse::fake(['input_tokens' => 14]), CountTokensResponse::fake(['input_tokens' => 14])]);
    $request = ['model' => 'claude-opus-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello!']]];

    expect(Anthropic::messages()->fits($request, 100))->toBeTrue()
        ->and(Anthropic::messages()->trimToFit($request, 100))->toBe($request);

    Anthropic::messages()->assertSent(2);
});

test('toVercelStream() sends a keep-alive while a server tool runs, and nothing for thinking that streams no text', function () {
    Anthropic::fake([CreateStreamedResponse::fake(CreateResponse::fake(['content' => [
        // thinking with its display omitted streams an empty delta, then the signature
        Blocks::thinking(''),
        Blocks::serverToolUse(),
        Blocks::text("Okay, let's check the weather for San Francisco, CA:"),
    ]]))]);
    Route::get('/stream', fn () => Anthropic::messages()->createStreamed([])
        // the search runs for 20 seconds
        ->onEvent(fn (CreateStreamedResponse $event) => $event->content_block_start->type === 'server_tool_use' ? $this->travel(20)->seconds() : null)
        ->toVercelStream());

    $parts = explode("\n\n", trim($this->get('/stream')->streamedContent()));

    expect($parts)->toContain(': keep-alive')
        ->and(array_filter($parts, fn (string $part): bool => str_contains($part, 'reasoning')))->toBe([]);
});

test('a stream cut off upstream is reported', function () {
    Exceptions::fake();
    Anthropic::fake([CreateStreamedResponse::fake(textStreamEvents(['Hello'], []))]);

    $this->get(streamRoute('toEventStream'))->streamedContent();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'The stream ended before the message did.');
});
