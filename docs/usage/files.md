---
title: Files
weight: 10
---

Upload a document once and reference it by `file_id` on later Messages calls. Good for repeated PDFs and images, and for reading outputs the code execution tool or Skills produce.

Anthropic currently flags this endpoint as beta on their side; the SDK sends the required header on every `Anthropic::files()` call, so there's nothing to configure.

## Uploading a file

```php
use Anthropic\Laravel\Facades\Anthropic;
use Illuminate\Support\Facades\Storage;

$file = Anthropic::files()->upload([
    'file' => Storage::disk('local')->readStream('contracts/lease.pdf'),
]);

$file->id;        // 'file_011CNha8iCJcU1wXNR6q4V8w'
$file->filename;  // 'lease.pdf'
$file->mimeType;  // 'application/pdf'
$file->sizeBytes; // 1024000
```

Any readable stream works: `fopen()`, `Storage::readStream()`, `$request->file('pdf')->openFile()->detach()`, or a raw PSR-7 stream. Max size is 500 MB per file, 500 GB per org.

Keep the `id` on an Eloquent model so you can reuse it later:

```php
class Document extends Model
{
    protected $fillable = ['user_id', 'filename', 'anthropic_file_id'];
}

$document = Document::create([
    'user_id' => auth()->id(),
    'filename' => $file->filename,
    'anthropic_file_id' => $file->id,
]);
```

## Referencing a file in a message

Drop the ID into a `document` content block. Note the `betas` key on the Messages call: the Messages endpoint also needs the Files API beta header when a `file_id` is referenced, and the SDK only auto-injects on `Anthropic::files()` calls. Pass it explicitly here:

```php
$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 1024,
    'betas' => ['files-api-2025-04-14'],
    'messages' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Summarise the key terms.'],
            [
                'type' => 'document',
                'source' => [
                    'type' => 'file',
                    'file_id' => $document->anthropic_file_id,
                ],
            ],
        ],
    ]],
]);
```

For images, swap `document` for `image`. Block and file-type pairing:

| File type | Block |
|-----------|-------|
| PDF, plain text | `document` |
| JPEG, PNG, GIF, WebP | `image` |
| Code execution inputs (CSV, XLSX, JSON, etc.) | `container_upload` |

If every Messages call in your app references uploaded files, skip the per-call `betas` and put the header on the global config instead:

```php
// config/anthropic.php
'beta' => ['files-api-2025-04-14'],
```

## Listing files

```php
$response = Anthropic::files()->list(['limit' => 50]);

foreach ($response->data as $file) {
    $file->id;
    $file->filename;
}
```

Pagination uses `limit` (default 20, max 1000), `after_id` with `lastId` to go forward, `before_id` with `firstId` to go back. The optional `scope_id` parameter filters by session.

## Retrieving metadata

```php
$file = Anthropic::files()->retrieveMetadata($document->anthropic_file_id);

$file->filename;
$file->sizeBytes;
$file->downloadable; // false for user-uploaded files
$file->scope?->type; // 'session' if the file came from a session
```

Good for a nightly sync job that checks your Eloquent records against Anthropic's side and flags anything that's drifted.

## Downloading a file

Only files produced by the [code execution tool](https://platform.claude.com/docs/en/agents-and-tools/tool-use/code-execution-tool) or [Skills](https://platform.claude.com/docs/en/build-with-claude/skills-guide) can be downloaded. Files you upload yourself can't. Check `downloadable` first.

`download()` returns raw bytes as a string:

```php
use Illuminate\Support\Facades\Storage;

$bytes = Anthropic::files()->download('file_011CPMxVD3fHLUhvTqtsQA5w');

Storage::disk('local')->put('outputs/chart.png', $bytes);
```

For larger outputs, run the download inside a queued job and stream it to S3:

```php
class DownloadAnthropicFile implements ShouldQueue
{
    public function __construct(public string $fileId, public int $documentId) {}

    public function handle(): void
    {
        $bytes = Anthropic::files()->download($this->fileId);

        $path = "anthropic-outputs/{$this->fileId}.bin";
        Storage::disk('s3')->put($path, $bytes);

        Document::find($this->documentId)->update(['storage_path' => $path]);
    }
}
```

## Deleting a file

```php
Anthropic::files()->delete($document->anthropic_file_id);

$document->update(['anthropic_file_id' => null]);
```

Deletes are permanent. In-flight Messages calls that already started may keep working briefly, but new requests using the same `file_id` get a 404.

## Errors worth catching

```php
use Anthropic\Exceptions\ErrorException;
use Illuminate\Support\Facades\Log;

try {
    $file = Anthropic::files()->upload([
        'file' => $request->file('pdf')->openFile()->detach(),
    ]);
} catch (ErrorException $e) {
    Log::warning('Anthropic file upload failed', [
        'type' => $e->getErrorType(),
        'message' => $e->getMessage(),
    ]);

    return back()->withErrors(['pdf' => 'Could not upload the document.']);
}
```

Common ones:

- `404`: file ID doesn't exist or belongs to another workspace
- `413`: file exceeds 500 MB
- `403`: org is over the 500 GB storage cap
- `400`: invalid filename (1 to 255 characters, no control characters, no `< > : " | ? * \ /`)

## Rate limits

Anthropic caps Files-related calls at roughly 100 per minute per org. If you're uploading in a loop, throttle with a queued job + rate-limited middleware:

```php
use Illuminate\Queue\Middleware\RateLimited;

public function middleware(): array
{
    return [new RateLimited('anthropic-files')];
}
```

```php
// AppServiceProvider boot()
RateLimiter::for('anthropic-files', fn () => Limit::perMinute(90));
```

Upload, list, metadata, download, and delete calls are all free. Tokens are only billed when the file is referenced in a Messages request.

---

For full request and response schemas, see the [Files page in the PHP docs](https://mozex.dev/docs/anthropic-php/v1/usage/files) or the [Files API guide](https://platform.claude.com/docs/en/build-with-claude/files) on the Anthropic docs.
