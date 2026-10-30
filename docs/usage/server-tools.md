---
title: Server Tools
weight: 5
---

Server tools run on Anthropic's infrastructure, not yours. Add them to the `tools` array and the results come back in the response.

The primary server tools are **web search** and **code execution**.

## Web search

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'tools' => [
        ['type' => 'web_search_20250305', 'name' => 'web_search'],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'When was Claude Shannon born?'],
    ],
]);
```

The response contains `server_tool_use` blocks, `web_search_tool_result` blocks, and final `text` blocks with citations to the sources:

```php
foreach ($response->content as $block) {
    if ($block->type === 'text' && $block->citations) {
        foreach ($block->citations as $citation) {
            // $citation['url'], $citation['title'], $citation['cited_text']
        }
    }
}

$response->usage->serverToolUse?->webSearchRequests; // 1
```

### Web search options

```php
'tools' => [
    [
        'type' => 'web_search_20250305',
        'name' => 'web_search',
        'max_uses' => 5,
        'allowed_domains' => ['example.com', 'docs.php.net'],
        'blocked_domains' => ['untrusted.com'],
        'user_location' => [
            'type' => 'approximate',
            'city' => 'San Francisco',
            'country' => 'US',
            'timezone' => 'America/Los_Angeles',
        ],
    ],
]
```

A newer version `web_search_20260209` adds dynamic filtering (Claude writes code to filter search results before they reach the context). It requires the code execution tool to be enabled alongside it. The latest, `web_search_20260318`, adds a `response_inclusion` parameter that drops consumed result blocks from the response.

## Code execution

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 4096,
    'tools' => [
        ['type' => 'code_execution_20250825', 'name' => 'code_execution'],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Calculate the compound interest on $1000 at 5% for 10 years.'],
    ],
]);
```

Claude writes Python/bash, runs it in a sandboxed container, and returns both the code and the result:

```php
$response->content[1]->type;                  // 'bash_code_execution_tool_result'
$response->content[1]->content['stdout'];     // '1628.89'
$response->content[1]->content['return_code']; // 0
```

### Container persistence

Code execution runs in a container that persists for 30 days. To reuse it across requests, pass the container ID back:

```php
$followUp = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 4096,
    'container' => $response->container['id'],
    'tools' => [
        ['type' => 'code_execution_20250825', 'name' => 'code_execution'],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Now plot that growth over time.'],
    ],
]);
```

Store the container ID on your model so follow-ups pick up where the first call left off:

```php
$conversation->update(['claude_container_id' => $response->container['id']]);

// Later...
$response = Anthropic::messages()->create([
    'container' => $conversation->claude_container_id,
    // ...
]);
```

### Files written inside the container

When Claude writes a file during code execution, a `container_upload` block appears in the response with the stored file's ID:

```php
foreach ($response->content as $block) {
    if ($block->type === 'container_upload') {
        $conversation->generatedFiles()->create([
            'anthropic_file_id' => $block->file_id,
        ]);
    }
}
```

Pair the `file_id` with the [Files API](https://platform.claude.com/docs/en/api/files) to download the contents when you need to hand the file off to a user or forward it to your own storage disk.

## MCP connector

The MCP connector (beta) lets the API call tools on a remote MCP server for you. Keep the server's token out of code, in your config or an encrypted column:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-sonnet-5-5',
    'max_tokens' => 1024,
    'betas' => ['mcp-client-2025-11-20'],
    'mcp_servers' => [[
        'type' => 'url',
        'url' => config('services.crm_mcp.url'),
        'name' => 'crm',
        'authorization_token' => $team->crm_mcp_token, // an 'encrypted' cast on the model
    ]],
    'tools' => [
        ['type' => 'mcp_toolset', 'mcp_server_name' => 'crm'],
    ],
    'messages' => [
        ['role' => 'user', 'content' => 'Which deals closed this week?'],
    ],
]);
```

The calls come back as `mcp_tool_use` blocks (with `server_name`) and `mcp_tool_result` blocks (with `is_error`). Your code doesn't run them; they record what happened on the server. Under the newer `mcp-client-2026-09-15` header a response can also start with an `mcp_tool_listing` block, so don't assume `content[0]` is text.

The advisor tool (`advisor_20260301`, beta) works the same way: it lets the model you call consult a stronger one, and its `advisor_tool_result` blocks go back to the API unchanged on later turns.

## Usage tracking

Server tool usage is billed separately from tokens. Check the counts:

```php
$response->usage->serverToolUse?->webSearchRequests;
$response->usage->serverToolUse?->webFetchRequests;
$response->usage->serverToolUse?->codeExecutionRequests;
$response->usage->serverToolUse?->toolSearchRequests;
```

## Error handling

Server tools can fail mid-response (rate limit, invalid input). The API still returns HTTP 200, but the result block contains an error:

```php
$response->content[1]->content['type'];       // 'web_search_tool_result_error'
$response->content[1]->content['error_code']; // 'max_uses_exceeded'
```

---

For result block types (`web_fetch_tool_result`, `code_execution_tool_result`, `bash_code_execution_tool_result`, etc.), programmatic tool calling, the MCP connector's options and tool listings, the advisor tool, tool version history, and pricing, see the [Server Tools page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/server-tools) or the [Web search tool](https://platform.claude.com/docs/en/agents-and-tools/tool-use/web-search-tool) and [Code execution tool](https://platform.claude.com/docs/en/agents-and-tools/tool-use/code-execution-tool) guides on the Anthropic docs.
