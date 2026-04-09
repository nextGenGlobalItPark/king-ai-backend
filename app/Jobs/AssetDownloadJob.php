<?php

namespace App\Jobs;

use App\Events\AiJobCompletedEvent;
use App\Models\AiJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads completed generation assets from vendor CDN to local storage.
 *
 * Dispatched after a job is marked 'completed' (from both polling and webhook paths).
 * Downloads each asset (video + thumbnail) to storage/app/public/generations/{user_id}/{job_id}/.
 * Replaces vendor URLs in ai_jobs.result with permanent local URLs.
 * Fires AiJobCompletedEvent AFTER download so push notifications contain our URLs.
 *
 * If download fails, the job still stays 'completed' with original vendor URLs
 * as a fallback — better to have expiring URLs than no URLs.
 */
class AssetDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 30, 60];
    public int $timeout = 300; // 5 min — videos can be large

    public function __construct(
        public AiJob $aiJob,
    ) {}

    public function handle(): void
    {
        $aiJob = $this->aiJob->fresh();

        if ($aiJob->status !== 'completed') {
            Log::debug('AssetDownloadJob skipped: job not completed', [
                'job_id' => $aiJob->id,
                'status' => $aiJob->status,
            ]);
            return;
        }

        $result = $aiJob->result;
        $assets = $result['assets'] ?? [];

        if (empty($assets)) {
            Log::info('AssetDownloadJob: no assets to download', ['job_id' => $aiJob->id]);
            // Still fire the event — job is complete, just no assets
            event(new AiJobCompletedEvent($aiJob));
            return;
        }

        $userId = $aiJob->user_id ?? 'anonymous';
        $jobFolder = "generations/{$userId}/{$aiJob->queue_job_id}";
        $updatedAssets = [];
        $updatedUrls = [];
        $allDownloaded = true;

        foreach ($assets as $index => $asset) {
            $updatedAsset = $asset;

            // Download video
            $videoUrl = $asset['url'] ?? null;
            if ($videoUrl) {
                $localPath = $this->downloadFile($videoUrl, $jobFolder, "video_{$index}");
                if ($localPath) {
                    $updatedAsset['url'] = Storage::disk('public')->url($localPath);
                    $updatedAsset['original_url'] = $videoUrl; // Keep original as backup
                    Log::debug('Video downloaded', ['job_id' => $aiJob->id, 'path' => $localPath]);
                } else {
                    $allDownloaded = false;
                    Log::warning('Video download failed, keeping vendor URL', [
                        'job_id' => $aiJob->id,
                        'url' => $videoUrl,
                    ]);
                }
            }

            // Download thumbnail
            $thumbnailUrl = $asset['thumbnail'] ?? null;
            if ($thumbnailUrl) {
                $localPath = $this->downloadFile($thumbnailUrl, $jobFolder, "thumb_{$index}");
                if ($localPath) {
                    $updatedAsset['thumbnail'] = Storage::disk('public')->url($localPath);
                    $updatedAsset['original_thumbnail'] = $thumbnailUrl;
                    Log::debug('Thumbnail downloaded', ['job_id' => $aiJob->id, 'path' => $localPath]);
                } else {
                    Log::warning('Thumbnail download failed, keeping vendor URL', [
                        'job_id' => $aiJob->id,
                        'url' => $thumbnailUrl,
                    ]);
                }
            }

            $updatedAssets[] = $updatedAsset;
            if (!empty($updatedAsset['url'])) {
                $updatedUrls[] = $updatedAsset['url'];
            }
        }

        // Update the result with local URLs
        $result['assets'] = $updatedAssets;
        $result['asset_urls'] = $updatedUrls;
        $result['assets_stored_locally'] = $allDownloaded;

        $aiJob->update(['result' => $result]);

        Log::info('AssetDownloadJob completed', [
            'job_id' => $aiJob->id,
            'assets_count' => count($updatedAssets),
            'all_downloaded' => $allDownloaded,
        ]);

        // Fire the event AFTER downloading so push notification has our URLs
        event(new AiJobCompletedEvent($aiJob));
    }

    /**
     * Download a file from URL to the public disk.
     *
     * @return string|null The relative storage path, or null on failure
     */
    private function downloadFile(string $url, string $folder, string $namePrefix): ?string
    {
        try {
            $response = Http::timeout(120)->get($url);

            if (!$response->successful()) {
                Log::warning('Download HTTP error', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            // Determine extension from Content-Type or URL
            $contentType = $response->header('Content-Type');
            $extension = $this->guessExtension($contentType, $url);

            $filename = "{$namePrefix}_" . Str::random(8) . ".{$extension}";
            $path = "{$folder}/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            return $path;

        } catch (\Exception $e) {
            Log::error('File download exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Guess file extension from content type or URL.
     */
    private function guessExtension(?string $contentType, string $url): string
    {
        // Try content type first
        $mimeMap = [
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if ($contentType) {
            $baseMime = strtolower(explode(';', $contentType)[0]);
            if (isset($mimeMap[$baseMime])) {
                return $mimeMap[$baseMime];
            }
        }

        // Fall back to URL extension
        $parsedPath = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION));

        if (in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            return $ext;
        }

        // Default to mp4 for video assets
        return 'mp4';
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AssetDownloadJob failed after retries', [
            'job_id' => $this->aiJob->id ?? null,
            'error' => $exception->getMessage(),
        ]);

        // Still fire the event with vendor URLs so the user gets notified
        $aiJob = $this->aiJob->fresh();
        if ($aiJob && $aiJob->status === 'completed') {
            event(new AiJobCompletedEvent($aiJob));
        }
    }
}
