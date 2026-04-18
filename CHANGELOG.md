# Changelog

All notable changes to `anthropic-laravel` will be documented in this file.

## 1.7.0 - 2026-04-18

### What's Changed

#### Added

**Files API**

- `Anthropic::files()` exposes the full Files resource: `upload`, `list`, `retrieveMetadata`, `download`, `delete`. Upload a document once and reference it by `file_id` in later Messages calls, or read outputs produced by the code execution tool and Skills.

```php
use Anthropic\Laravel\Facades\Anthropic;
use Illuminate\Support\Facades\Storage;

$file = Anthropic::files()->upload([
    'file' => Storage::disk('local')->readStream('doc.pdf'),
]);

$response = Anthropic::messages()->create([
    'model' => 'claude-opus-4-6',
    'max_tokens' => 1024,
    'betas' => ['files-api-2025-04-14'],
    'messages' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Summarise this.'],
            ['type' => 'document', 'source' => ['type' => 'file', 'file_id' => $file->id]],
        ],
    ]],
]);

```
- Anthropic currently flags this endpoint as beta. The SDK auto-injects the required `anthropic-beta: files-api-2025-04-14` header on every `Anthropic::files()` call, so there's nothing to configure. When you reference a `file_id` inside a Messages call, pass `'betas' => ['files-api-2025-04-14']` on the Messages call as well; the Messages endpoint also needs the header when a file is referenced. If every Messages call in your app references uploaded files, put the beta globally via `config('anthropic.beta')` instead.

**Testing**

- `FileResponse::fake()`, `FileListResponse::fake()`, and `DeletedFileResponse::fake()` now plug into `Anthropic::fake([...])` with `assertSent(Files::class, ...)` assertions, same as every other resource.

**Documentation**

- New [Files guide](https://mozex.dev/docs/anthropic-laravel/v1/usage/files) covering upload, list, retrieve, download, delete, and Messages integration with Laravel-idiomatic patterns: `Storage::readStream` for uploads, queued jobs that stream downloads to S3, Eloquent `anthropic_file_id` columns, and the `RateLimiter::for('anthropic-files', fn () => Limit::perMinute(90))` throttle for bulk uploads.

**Boost skill**

- `resources/boost/skills/anthropic-laravel/SKILL.md` picks up a new Files section covering the five methods, the Messages-side beta gotcha, block-to-file-type pairing, and the Eloquent + queued-job patterns. Triggers expanded to include `Anthropic::files()`, `FileResponse`, and file upload/reference work.

#### Improved

- Bump `mozex/anthropic-php` to `^1.7.0`. See the [PHP 1.7.0 release notes](https://github.com/mozex/anthropic-php/releases/tag/1.7.0) for the underlying implementation, including the auto-injection mechanism and the 40 Files-specific tests behind this release.

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.6.0...1.7.0

## 1.6.0 - 2026-04-18

### What's Changed

* Add Anthropic beta header support

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.5.0...1.6.0

## 1.5.0 - 2026-04-14

### What's Changed

#### Added

**Laravel Boost skill**

- Ship a Laravel Boost skill at `resources/boost/skills/anthropic-laravel/SKILL.md` so AI coding assistants pick up package conventions automatically when working in a Laravel project. Activates on any mention of the `Anthropic` facade, the underlying SDK classes, or Claude-related work.
- Covers the full surface: messages, streaming, tool use, extended thinking, web search, code execution, citations, batches, token counting, models, testing with `Anthropic::fake()`, and error handling with the queue-retry pattern.
- Calls out the five gotchas that actually cause bugs: inconsistent property casing across DTOs, the `caller?->type === 'direct'` filter required in tool dispatchers, `pause_turn` resume semantics, refusal responses arriving with HTTP 200, and mid-stream errors throwing after the response started.
- Designed for progressive disclosure: keeps Laravel patterns and pitfalls in full, defers exhaustive reference material to Context7 (`/mozex/anthropic-laravel`, `/mozex/anthropic-php`) or direct fetches from the docs site, both of which serve markdown for AI agents.

**Documentation site**

- Ship the full documentation at [mozex.dev/docs/anthropic-laravel/v1](https://mozex.dev/docs/anthropic-laravel/v1) with dedicated pages for every feature: introduction, configuration, messages, streaming, tool use, thinking, server tools, citations, token counting, models, batches, completions, meta information, error handling, and testing.
- Every page leads with Laravel-idiomatic examples: Facade calls, `ShouldQueue` jobs, `Cache::remember` for the model list, `Storage::disk` for vision and PDF citations, broadcasting from streamed responses, scheduled commands for batch polling, and `bootstrap/app.php` `reportable()` callbacks for silencing overload errors.
- Each page links back to the matching page in the [PHP SDK docs](https://mozex.dev/docs/anthropic-php/v1) for deeper schema reference, so the Laravel docs stay focused on the 80% Laravel path without duplicating SDK material.

#### Improved

- Bump `mozex/anthropic-php` to `^1.5.0`. All API additions land transparently through the `Anthropic` facade: the `caller` object on tool blocks, `container_upload` blocks, `stop_details` on refusals, the typed model `capabilities` tree, `inferenceGeo` on usage, and Priority Tier rate-limit headers as typed properties on `MetaInformation`. See the [PHP 1.5.0 release notes](https://github.com/mozex/anthropic-php/releases/tag/1.5.0) for the full feature list.

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.4.0...1.5.0

## 1.4.0 - 2026-04-01

* Expand README to document new Anthropic features and improved testing controls
* bump mozex/anthropic-php dependency

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.3.3...1.4.0

## 1.3.3 - 2026-03-05

### What's Changed

* Laravel 13.x Compatibility by @laravel-shift in https://github.com/mozex/anthropic-laravel/pull/12
* updated model names for consistency

### New Contributors

* @laravel-shift made their first contribution in https://github.com/mozex/anthropic-laravel/pull/12

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.3.2...1.3.3

## 1.3.2 - 2026-03-05

* bump anthropic-php
* update readme
* sync version with anthropic-php

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.3.0...1.3.2

## 1.3.0 - 2026-03-04

### What's Changed

* Bump `mozex/anthropic-php` to ^1.2.0
* bump dependencies
* dropped php 8.1 support
* dropped laravel 9 and 10 support

See the [anthropic-php UPGRADING.md](https://github.com/mozex/anthropic-php/blob/main/UPGRADING.md) for details on new features and changes. All changes are backwards compatible.

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.2.0...1.3.0

## 1.2.0 - 2025-02-25

### What's Changed

* Add Laravel 12 compatibility by @mozex in https://github.com/mozex/anthropic-laravel/pull/6

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.1.0...1.2.0

## 1.1.0 - 2024-12-21

- Changed underlying mozex/anthropic-php package version from v1.0.3 to v1.1.0

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.0.3...1.1.0

## 1.0.3 - 2024-12-20

* [bump dependencies](https://github.com/mozex/anthropic-laravel/commit/1805d40bbcc96be5bc52d7c5572e51cb99bc6724)
* [add php 8.4 to workflow](https://github.com/mozex/anthropic-laravel/commit/cb5960634daf69a9506c1544dc601345a59fae3a)
* Changed underlying mozex/anthropic-php package version from v1.0.2 to v1.0.3

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.0.2...1.0.3

## 1.0.2 - 2024-08-15

### What's Changed

* Bump dependencies
* Changed underlying `mozex/anthropic-php` package version from v1.0.1 to v1.0.2

**Full Changelog**: https://github.com/mozex/anthropic-laravel/compare/1.0.1...1.0.2

## 1.0.1 - 2024-05-01

- fix facade docs

## 1.0.0 - 2024-05-01

Initial Release
