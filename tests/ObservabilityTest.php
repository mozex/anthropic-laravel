<?php

declare(strict_types=1);

use Anthropic\Contracts\ClientContract;
use Anthropic\Exceptions\RateLimitException;
use Anthropic\Laravel\Events\RequestSending;
use Anthropic\Laravel\Facades\Anthropic;
use Anthropic\Laravel\Middleware\Concerns\ReadsMessages;
use Anthropic\Responses\Messages\CreateResponse;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Events\RequestSending as LaravelRequestSending;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Core as Nightwatch;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A request the tests send.
 *
 * @return array<string, mixed>
 */
function observedRequest(): array
{
    return ['model' => 'claude-opus-5-5', 'max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => 'Hello']]];
}

/**
 * A request to an MCP server, from the MCP connector docs, whose `authorization_token` no recorder may keep.
 *
 * @return array<string, mixed>
 */
function observedMcpRequest(): array
{
    return [
        'model' => 'claude-opus-5-5',
        'max_tokens' => 1024,
        'mcp_servers' => [['type' => 'url', 'url' => 'https://example-server.modelcontextprotocol.io/sse', 'name' => 'example-mcp', 'authorization_token' => 'YOUR_TOKEN']],
        'tools' => [['type' => 'mcp_toolset', 'mcp_server_name' => 'example-mcp']],
        'messages' => [['role' => 'user', 'content' => 'What tools do you have available?']],
    ];
}

/**
 * The spend-cap 429 of the rate limits page.
 *
 * @return array<string, mixed>
 */
function observedSpendCap(): array
{
    return [
        'type' => 'error',
        'error' => [
            'type' => 'rate_limit_error',
            'message' => "You have reached your API usage limits: your organization has crossed its monthly API usage threshold, set based on your organization's API tier. You will regain access on 2026-09-01 at 00:00 UTC.",
            'details' => ['error_code' => 'enforced_spend_limit_reached'],
        ],
        'request_id' => 'req_018EeWyXxfu5pfWkrYcMdjWG',
    ];
}

beforeEach(function () {
    config(['anthropic.api_key' => 'sk-ant-test', 'anthropic.http.laravel_client' => true]);
});

afterEach(function () {
    Facade::clearResolvedInstances();
    Telescope::stopRecording();
    Telescope::flushEntries();
    Telescope::$hiddenRequestHeaders = ['authorization', 'php-auth-pw'];
});

test("Laravel's exception handler logs an API error with its request ID, type, status, retry-after and error code", function () {
    Http::fake(['api.anthropic.com/*' => Http::response(observedSpendCap(), 429, ['retry-after' => '30'])]);
    $logged = new ArrayObject;
    Event::listen(fn (MessageLogged $message) => $logged->append($message->context));

    try {
        Anthropic::messages()->create(observedRequest());
    } catch (RateLimitException $e) {
        app(ExceptionHandler::class)->report($e);
    }

    expect($logged[0])->toMatchArray([
        'anthropic_request_id' => 'req_018EeWyXxfu5pfWkrYcMdjWG',
        'status' => 429,
        'type' => 'rate_limit_error',
        'retry_after' => 30,
        'error_code' => 'enforced_spend_limit_reached',
    ]);
});

test('with context on, every answer\'s request ID and a non-streamed answer\'s tokens reach Context, its logs and its jobs', function () {
    config(['anthropic.context' => true, 'logging.channels.observed' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_011CSHoEeqs5C35K2UUqR7Fy'])
        ->push(observedSpendCap(), 429)]);

    Anthropic::messages()->create(observedRequest());

    expect(fn () => Anthropic::messages()->create(observedRequest()))
        ->toThrow(RateLimitException::class);

    // the fake's usage is 10 in and 20 out; the error's request ID comes from its body
    expect(Context::get('anthropic_request_ids'))->toBe(['req_011CSHoEeqs5C35K2UUqR7Fy', 'req_018EeWyXxfu5pfWkrYcMdjWG'])
        ->and(Context::get('anthropic_input_tokens'))->toBe(10)
        ->and(Context::get('anthropic_output_tokens'))->toBe(20)
        // what a queued job carries
        ->and(Context::dehydrate()['data'] ?? [])->toHaveKey('anthropic_request_ids');

    Log::channel('observed')->info('Reply sent');
    $handler = Log::channel('observed')->getLogger()->getHandlers()[0];

    expect($handler->getRecords()[0]->extra['anthropic_request_ids'] ?? null)->toBe(['req_011CSHoEeqs5C35K2UUqR7Fy', 'req_018EeWyXxfu5pfWkrYcMdjWG']);
});

test('Context is read on each call, so the next job\'s gets only its own request IDs', function () {
    config(['anthropic.context' => true]);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_011CSHoEeqs5C35K2UUqR7Fy'])
        ->push(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_018EeWyXxfu5pfWkrYcMdjWG'])]);
    $client = app(ClientContract::class);

    $client->messages()->create(observedRequest());

    // what a queue worker does between jobs
    app()->forgetScopedInstances();

    $client->messages()->create(observedRequest());

    expect(Context::get('anthropic_request_ids'))->toBe(['req_018EeWyXxfu5pfWkrYcMdjWG']);
});

test('without context on, nothing is added', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_011CSHoEeqs5C35K2UUqR7Fy'])]);

    Anthropic::messages()->create(observedRequest());

    expect(Context::all())->toBe([]);
});

test('with telescope on, each attempt is a tagged client request without a credential', function () {
    config(['anthropic.http.telescope' => true, 'anthropic.headers' => ['X-Request-Source' => 'billing-report']]);
    Telescope::hideRequestHeaders(['x-request-source']);
    Telescope::startRecording(false);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_011CSHoEeqs5C35K2UUqR7Fy'])]);

    Anthropic::messages()->create(observedMcpRequest());

    /** @var IncomingEntry $entry */
    $entry = Telescope::$entriesQueue[0];

    expect(Telescope::$entriesQueue)->toHaveCount(1)
        ->and($entry->tags)->toBe(['anthropic', 'model:claude-opus-5-5', 'req:req_011CSHoEeqs5C35K2UUqR7Fy'])
        ->and($entry->content['method'])->toBe('POST')
        ->and($entry->content['uri'])->toBe('https://api.anthropic.com/v1/messages')
        ->and($entry->content['headers'])->not->toHaveKey('x-api-key')
        ->and($entry->content['headers']['x-request-source'])->toBe('********')
        ->and($entry->content['payload']['mcp_servers'][0]['authorization_token'])->toBe('********')
        ->and($entry->content['response_status'])->toBe(200)
        ->and($entry->content['response'])->toBe(CreateResponse::fake()->toArray())
        ->and($entry->content['duration'])->toBeInt()
        ->and(json_encode($entry->content))->not->toContain('sk-ant-test')
        ->and(json_encode($entry->content))->not->toContain('YOUR_TOKEN');
});

test('telescope records a failed attempt, and nothing while it isn\'t recording', function () {
    config(['anthropic.http.telescope' => true]);
    Http::fake(['api.anthropic.com/*' => Http::failedConnection()]);

    expect(fn () => Anthropic::messages()->create(observedMcpRequest()))->toThrow(Exception::class);
    expect(Telescope::$entriesQueue)->toBe([]);

    Telescope::startRecording(false);

    expect(fn () => Anthropic::connection()->messages()->create(observedMcpRequest()))->toThrow(Exception::class);

    expect(Telescope::$entriesQueue)->toHaveCount(1)
        ->and(Telescope::$entriesQueue[0]->tags)->toBe(['anthropic', 'model:claude-opus-5-5'])
        ->and(Telescope::$entriesQueue[0]->content)->not->toHaveKey('response_status');
});

test("Nightwatch's middleware runs on every attempt when it's installed, unless opted out, disabled, or Laravel's client already records", function (array $config, int $runs) {
    $nightwatch = new class
    {
        public int $runs = 0;

        public function guzzleMiddleware(): callable
        {
            return function (callable $handler): callable {
                $this->runs++;

                return $handler;
            };
        }
    };
    app()->instance(Nightwatch::class, $nightwatch);
    // the innermost Guzzle middleware answers, so no request leaves
    config([...$config, 'anthropic.http.middleware' => [fn (callable $handler): callable => fn () => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(CreateResponse::fake()->toArray())))]]);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray())]);

    Anthropic::messages()->create(observedRequest());
    Anthropic::messages()->create(observedRequest());

    expect($nightwatch->runs)->toBe($runs);
})->with([
    'installed' => [['anthropic.http.laravel_client' => false], 2],
    'opted out' => [['anthropic.http.laravel_client' => false, 'anthropic.http.nightwatch' => false], 0],
    'disabled' => [['anthropic.http.laravel_client' => false, 'nightwatch.enabled' => false], 0],
    "Laravel's client on" => [['anthropic.http.laravel_client' => true], 0],
]);

test('a streamed answer is recorded without its body, and adds its request ID but no tokens', function () {
    config(['anthropic.context' => true, 'anthropic.http.telescope' => true]);
    Telescope::startRecording(false);
    $stream = file_get_contents(__DIR__.'/../vendor/mozex/anthropic-php/src/Testing/Responses/Fixtures/Messages/CreateStreamedResponseFixture.txt');
    Http::fake(['api.anthropic.com/*' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream', 'request-id' => 'req_018EeWyXxfu5pfWkrYcMdjWG'])]);

    $types = array_map(fn (object $event): string => $event->type, iterator_to_array(Anthropic::messages()->createStreamed(['model' => 'claude-opus-5-5', 'max_tokens' => 1024, 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Hello']]])));

    expect($types)->toContain('message_start', 'message_delta')
        ->and(Telescope::$entriesQueue[0]->content['response'])->toBe('Stream Response')
        ->and(Context::get('anthropic_request_ids'))->toBe(['req_018EeWyXxfu5pfWkrYcMdjWG'])
        ->and(Context::has('anthropic_input_tokens'))->toBeFalse();
});

test('telescope purges a JSON answer over 64 KB', function () {
    config(['anthropic.http.telescope' => true]);
    Telescope::startRecording(false);
    // composed: a long answer
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake(['content' => [['type' => 'text', 'text' => str_repeat('Claude ', 10_000)]]])->toArray())]);

    Anthropic::messages()->create(observedRequest());

    expect(Telescope::$entriesQueue[0]->content['response'])->toBe('Purged By Telescope');
});

test('Context keeps the last 20 request IDs, and counts tokens only from a Messages answer', function () {
    config(['anthropic.context' => true]);
    Context::add('anthropic_request_ids', array_map(fn (int $n): string => 'req_'.$n, range(1, 20)));
    Http::fake([
        'api.anthropic.com/v1/messages/count_tokens' => Http::response(['input_tokens' => 14, 'usage' => ['input_tokens' => 14, 'output_tokens' => 0]], 200, ['request-id' => 'req_011CSHoEeqs5C35K2UUqR7Fy']),
        'api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray(), 200, ['request-id' => 'req_018EeWyXxfu5pfWkrYcMdjWG']),
    ]);

    // composed: a usage object on the token count's answer, which isn't consumption
    Anthropic::messages()->countTokens(['model' => 'claude-opus-5-5', 'messages' => [['role' => 'user', 'content' => 'Hello']]]);
    Anthropic::messages()->create(observedRequest());

    $ids = Context::get('anthropic_request_ids');

    expect($ids)->toHaveCount(20)
        ->and($ids[0])->toBe('req_3')
        ->and(array_slice($ids, -2))->toBe(['req_011CSHoEeqs5C35K2UUqR7Fy', 'req_018EeWyXxfu5pfWkrYcMdjWG'])
        ->and(Context::get('anthropic_input_tokens'))->toBe(10);
});

test('an upload is never read for the redacted copy', function () {
    $reads = new ArrayObject;
    // composed: an upload whose bytes happen to hold the field's name
    $upload = new class(Utils::streamFor('%PDF "authorization_token"'), $reads) implements StreamInterface
    {
        use StreamDecoratorTrait;

        public function __construct(private StreamInterface $stream, private ArrayObject $reads) {}

        public function read(int $length): string
        {
            $this->reads->append($length);

            return $this->stream->read($length);
        }

        public function getContents(): string
        {
            $this->reads->append('all');

            return $this->stream->getContents();
        }

        public function __toString(): string
        {
            $this->reads->append('all');

            return (string) $this->stream;
        }
    };
    $recorder = new class
    {
        use ReadsMessages;

        public static function copy(RequestInterface $request): RequestInterface
        {
            return self::withoutSecrets($request);
        }
    };

    $copy = $recorder::copy(new Request('POST', 'https://api.anthropic.com/v1/files', ['Content-Type' => 'multipart/form-data; boundary=anthropic'], $upload));

    expect($reads)->toHaveCount(0)
        ->and($copy->getBody())->toBe($upload);
});

test('no event carries an MCP server\'s authorization token', function () {
    config(['anthropic.http.laravel_events' => true]);
    Http::fake(['api.anthropic.com/*' => Http::response(CreateResponse::fake()->toArray())]);
    $sent = new ArrayObject;
    Event::listen(fn (RequestSending $event) => $sent->append($event->body));
    Event::listen(fn (LaravelRequestSending $event) => $sent->append($event->request->data()));

    Anthropic::messages()->create(observedMcpRequest());

    expect($sent)->toHaveCount(2)
        ->and($sent[0]['mcp_servers'][0]['authorization_token'])->toBe('********')
        ->and($sent[1]['mcp_servers'][0]['authorization_token'])->toBe('********');

    // the request that went out still carried it
    Http::assertSent(fn ($request): bool => $request['mcp_servers'][0]['authorization_token'] === 'YOUR_TOKEN');
});
