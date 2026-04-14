---
title: Citations
weight: 6
---

Citations let you verify the sources behind Claude's claims. When enabled, Claude breaks its response into multiple text blocks where each cited claim includes a `citations` array pointing to exact locations in your source material.

## Document citations

Enable citations on a document content block:

```php
use Anthropic\Laravel\Facades\Anthropic;

$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 1024,
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'text',
                        'media_type' => 'text/plain',
                        'data' => 'The grass is green. The sky is blue.',
                    ],
                    'title' => 'My Document',
                    'citations' => ['enabled' => true],
                ],
                ['type' => 'text', 'text' => 'What color is the grass and sky?'],
            ],
        ],
    ],
]);
```

The response comes back as multiple text blocks. Some have citations, some don't:

```php
$response->content[1]->text;      // 'the grass is green'
$response->content[1]->citations[0]['type'];           // 'char_location'
$response->content[1]->citations[0]['cited_text'];     // 'The grass is green.'
$response->content[1]->citations[0]['start_char_index']; // 0
$response->content[1]->citations[0]['end_char_index'];   // 20
```

## Documents from Laravel Storage

For a PDF stored on disk:

```php
use Illuminate\Support\Facades\Storage;

$pdf = Storage::disk('documents')->get('contract.pdf');

$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 2048,
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => base64_encode($pdf),
                    ],
                    'title' => 'Service Agreement',
                    'citations' => ['enabled' => true],
                ],
                ['type' => 'text', 'text' => 'What are the termination clauses?'],
            ],
        ],
    ],
]);
```

## Rendering citations in a view

A common pattern is to loop over the content blocks in a Blade template:

```blade
@foreach ($response->content as $block)
    @if ($block->type === 'text')
        <span>{{ $block->text }}</span>

        @if ($block->citations)
            @foreach ($block->citations as $citation)
                <sup>
                    <a href="#citation-{{ $loop->parent->index }}"
                       title="{{ $citation['cited_text'] }}">
                        [{{ $loop->parent->index + 1 }}]
                    </a>
                </sup>
            @endforeach
        @endif
    @endif
@endforeach
```

## Citation location types

The location fields depend on the source:

| Type | Source | Key fields |
|------|--------|------------|
| `char_location` | Plain text documents | `start_char_index`, `end_char_index` |
| `page_location` | PDF documents | `start_page_number`, `end_page_number` |
| `content_block_location` | Custom content blocks | `start_block_index`, `end_block_index` |
| `web_search_result_location` | Web search results | `url`, `title`, `encrypted_index` |
| `search_result_location` | Search results | `source`, `title`, `search_result_index` |

## Streaming citations

When [streaming](./streaming.md), citations arrive as `citations_delta` events:

```php
foreach ($stream as $response) {
    if ($response->delta->type === 'citations_delta') {
        $response->delta->citation['type'];      // 'char_location'
        $response->delta->citation['cited_text']; // 'The grass is green.'
    }
}
```

---

For multiple documents, all location type fields, custom content chunking, and web search citations, see the [Citations page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/citations) or the [Citations guide](https://platform.claude.com/docs/en/build-with-claude/citations) on the Anthropic docs.
