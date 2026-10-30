---
title: Tool Use
weight: 3
---

Tool use (function calling) lets you give Claude custom functions it can call during a conversation. Define the tools, Claude decides when to call them, your code executes them.

## Defining tools

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'tools' => [
        [
            'name' => 'get_weather',
            'description' => 'Get the current weather in a given location',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city and state, e.g. San Francisco, CA',
                    ],
                ],
                'required' => ['location'],
            ],
        ],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'What is the weather in San Francisco?'],
    ],
]);
```

Write detailed descriptions. Claude uses them to decide when to call each tool.

## Reading tool calls

When Claude decides to use a tool, the response contains `tool_use` content blocks:

```php
$response->content[1]->type;              // 'tool_use'
$response->content[1]->id;                // 'toolu_01RnYGkgJusAzXvcySfZ2Dq7'
$response->content[1]->name;              // 'get_weather'
$response->content[1]->input['location']; // 'San Francisco, CA'

$response->stop_reason; // 'tool_use'
```

## Sending tool results back

Execute the tool in your Laravel code, then send the result back in a second request:

```php
// First request
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'tools' => $tools,
    'messages' => [
        ['role' => 'user', 'content' => 'What is the weather in San Francisco?'],
    ],
]);

// Execute the tool (your code)
$weather = app(WeatherService::class)->get(
    $response->content[1]->input['location']
);

// Second request with the result
$followUp = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'tools' => $tools,
    'messages' => [
        ['role' => 'user', 'content' => 'What is the weather in San Francisco?'],
        ['role' => 'assistant', 'content' => $response->toArray()['content']],
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => $response->content[1]->id,
                    'content' => json_encode($weather),
                ],
            ],
        ],
    ],
]);

echo collect($followUp->content)->firstWhere('type', 'text')?->text;
// "The current weather in San Francisco is 65°F with partly cloudy skies."
```

The `tool_use_id` in the result must match the `id` from Claude's tool call.

## Dispatching tool calls to services

A clean pattern is to map tool names to Laravel service classes:

```php
$toolRegistry = [
    'get_weather' => WeatherService::class,
    'search_products' => ProductSearchService::class,
    'send_email' => EmailService::class,
];

$toolsets = [
    'computer' => ComputerActions::class,
    'browser' => BrowserActions::class,
];

foreach ($response->content as $block) {
    if ($block->type !== 'tool_use') {
        continue;
    }

    if ($block->toolset_name !== null) {
        // A computer or browser toolset member: dispatch on the pair, echo the toolset
        $results[] = [
            'type' => 'tool_result',
            'tool_use_id' => $block->id,
            'toolset_name' => $block->toolset_name,
            'content' => app($toolsets[$block->toolset_name])->run($block->name, $block->input),
        ];

        continue;
    }

    $results[] = [
        'type' => 'tool_result',
        'tool_use_id' => $block->id,
        'content' => json_encode(app($toolRegistry[$block->name])->handle($block->input)),
    ];
}
```

Each service handles one tool. The dispatcher finds the right class via the container, which means you get full dependency injection, interfaces, and testability.

The `toolset_name` check matters once you give Claude the computer or browser toolset (`['type' => 'computer_toolset_20260801']`). Their member tools (`screenshot`, `left_click`, `navigate` ...) arrive as ordinary `tool_use` blocks, and a custom tool of yours can share a member's name. Dispatch on `toolset_name` and `name` together, never on `name` alone. Send `toolset_name` back on every member's result and only on those: the API rejects a member result without it.

Calls Claude makes from its code execution sandbox ([programmatic tool calling](https://mozex.dev/docs/anthropic-php/v1/usage/server-tools#calling-your-tools-from-code-execution)) come through the same loop with `caller->type` set to `'code_execution_20260120'`. Run them like any other call: the sandbox waits for your answer, about four minutes at most. The reply to them holds only `tool_result` blocks, no text, and the request passes the paused response's `container` ID.

## Error results

If a tool fails, tell Claude with `is_error`:

```php
[
    'type' => 'tool_result',
    'tool_use_id' => $toolCallId,
    'is_error' => true,
    'content' => 'Location not found. Please check the city name.',
]
```

Claude will typically apologize and retry or ask for clarification.

## Controlling tool use

The `tool_choice` parameter influences when Claude uses tools:

```php
// Force a specific tool
'tool_choice' => ['type' => 'tool', 'name' => 'get_weather']

// Force at least one tool
'tool_choice' => ['type' => 'any']

// Let Claude decide (default)
'tool_choice' => ['type' => 'auto']

// Prevent tool use
'tool_choice' => ['type' => 'none']
```

Claude Opus 5.5, Sonnet 5.5, Fable 5.1 and Mythos 5.1 return a 400 for `any` and `tool`, and so does budget-based [thinking](./thinking.md) on any model; use `auto` with `'strict' => true` there.

---

For the full tool use workflow, multiple tool calls, the computer and browser toolsets, strict schema validation, and the complete `tool_choice` reference, see the [Tool Use page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/tool-use) or the [Tool use guide](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview) on the Anthropic docs.
