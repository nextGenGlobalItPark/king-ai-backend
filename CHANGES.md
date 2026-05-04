# Triage Fixes — April/May 2026

This batch addresses bugs found while reviewing the latest codebase. No new
features, no schema changes, no destructive migrations. Each fix is small and
isolated; the goal is correctness and removing dead code, not refactoring.

## Fixed

### 1. Schedule wiring (Laravel 12)

`bootstrap/app.php` — added `->withSchedule(...)` registering the
`generations:cleanup` command (daily at 03:00, with `withoutOverlapping()` and
`onOneServer()`).

Why: Laravel 12 ignores `app/Console/Kernel.php`. The schedule defined there
was never running. Deleted the dead `app/Console/Kernel.php` and
`app/Http/Kernel.php` to prevent confusion. (Vidu imports were intentionally
NOT scheduled — those commands remain as orphaned files; remove on a future
Vidu cleanup pass.)

Also updated `Deployment.md` and `Architecture.md` to reflect the new
schedule location and added a note that the VPS cron must invoke
`php artisan schedule:run` every minute for any of this to fire.

### 2. TemplateResource metadata never resolved

`app/Http/Resources/TemplateResource.php` — `whenNotNull($value, $closure)`
was being called like `when($value, $closure)`, but `whenNotNull`'s second
arg is a default value, not a callback. Result: when `metadata` was non-null,
the closure was discarded and `resolveMetadata()` never ran — so
`sample_content_index` and `preview_video_content_index` were returned to
the mobile client as raw indexes instead of resolved URLs.

Switched to `$this->when(! is_null($this->metadata), fn () => $this->resolveMetadata(...))`.

### 3. /api/status race condition

`app/Http/Controllers/AiController.php::status()` — replaced `firstOrFail()`
with a soft `first()` lookup. When the AiJob row hasn't been written yet
(race between `/generate` returning and the queue worker creating the row),
the endpoint now returns a synthetic `{"status": "queued"}` response so the
client can keep polling without seeing a misleading 404.

### 4. Polling backoff broken under Carbon 3

`app/Jobs/AiPollingJob.php` — Carbon 3 changed `diffInSeconds()` to return
**signed** values by default. `now()->diffInSeconds($pastDate)` returns a
negative integer; the existing `max(0, ...)` clamped it to 0 every time, so
the backoff interval index stayed at 0 and `[10, 20, 40, 60]` collapsed to
"always poll every 10s". Fixed by passing `true` as the second argument
(absolute) to `diffInSeconds`.

### 5. FCM token pruning unreliable

`app/Services/FCMPushService.php` — `$failure->target()` returns a
`MessageTarget` object in `kreait/firebase-php` v7+, not a string. Cast
explicitly to string before logging and using in the WHERE clause so the
`devices` row gets reliably deactivated regardless of PDO driver coercion.

### 6. Upload endpoint adds absolute URL (additive-only)

`app/Http/Controllers/UploadController.php::upload()` — response now
includes a new `url` field with the absolute URL via
`Storage::disk('public')->url(...)`. The original `path` field is
unchanged (still returns `'storage/templates/<uuid>.jpg'`) so the mobile
client keeps working without coordination. New clients can consume `url`
directly instead of concatenating `APP_URL + '/' + path`.

Response shape:
```json
{
  "data": {
    "path": "storage/templates/<uuid>.jpg",  // unchanged, for old clients
    "url":  "https://king-ai.../storage/templates/<uuid>.jpg"  // NEW
  }
}
```

### 7. Float fields no longer truncated

`app/Services/AiGenerationService.php::buildFromTemplate()` — `'number'`
type was casting through `(int)` along with `'integer'`, silently
truncating decimal values like `5.5` to `5`. Split into `'integer' => (int)`,
`'number'/'float' => (float)`.

### 8. Download URL index uses foreach key

`app/Http/Controllers/HistoryController.php::formatHistoryItem()` —
replaced `array_search($asset, $assets)` with the foreach `$index`. The
old code did a loose comparison across all asset arrays (slow + brittle —
duplicate-shaped assets would clash); the index is already in scope.

### 10. Removed legacy poll strategies

Deleted `app/Strategies/KlingPollStrategy.php` and `ViduPollStrategy.php`.
`PollStrategyResolver` always returns `GenericPollStrategy`; the legacy
classes hadn't been resolved since the generic refactor.

### 11. Removed stale `extra_params` cast

`app/Models/AiFeatureConfig.php` — dropped the `'extra_params' => 'array'`
cast. The column was removed in migration
`2025_12_06_142205_remove-param_mapping-default_params-extra_params-...`.

## Known issues NOT addressed in this round

These are flagged for awareness; they didn't make it into this PR to keep
scope tight. Triage as you see fit.

- `app/Http/Requests/StoreAiFeatureConfigRequest.php` and
  `UpdateAiFeatureConfigRequest.php` are unreferenced dead code (the routes
  use the type-specific Static-Token / JWT request classes). They also still
  validate columns that no longer exist (`param_mapping`, `default_params`,
  `extra_params`). Safe to delete on a separate cleanup pass.
- `app/Console/Commands/ViduTemplateImport.php` references a `ViduTemplate`
  model that doesn't exist — would crash on invocation. Left in place pending
  the broader Vidu deprecation.
- `INSTRUCTIONS.md` is stale (talks about `/generate/text-summary`, doesn't
  mention webhooks/history/templates). `Architecture.md` is the source of
  truth.
- All models still use `protected $guarded = ['']` (full mass-assignment
  open). This is the previously-deferred decision and untouched here.
