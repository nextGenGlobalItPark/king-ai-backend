<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Clean up old generation assets from local storage.
 *
 * Usage:
 *   php artisan generations:cleanup              # Default: 30 days
 *   php artisan generations:cleanup --days=7     # Custom retention
 *   php artisan generations:cleanup --dry-run    # Preview without deleting
 *
 * Scheduled via bootstrap/app.php (->withSchedule). Runs daily at 03:00.
 */
class CleanupGenerations extends Command
{
    protected $signature = 'generations:cleanup
                            {--days=30 : Delete assets older than this many days}
                            {--dry-run : Preview what would be deleted without actually deleting}';

    protected $description = 'Clean up old generation assets from storage to manage disk space';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Cleaning up generation assets older than {$days} days (before {$cutoff->toDateTimeString()})");

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be deleted');
        }

        // Find completed jobs older than the cutoff that have locally stored assets
        $jobs = AiJob::where('status', 'completed')
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('result')
            ->cursor(); // Memory efficient for large datasets

        $jobsProcessed = 0;
        $filesDeleted = 0;
        $bytesFreed = 0;
        $errors = 0;

        foreach ($jobs as $job) {
            $result = $job->result;

            // Skip if assets aren't stored locally
            if (empty($result['assets_stored_locally'])) {
                continue;
            }

            $assets = $result['assets'] ?? [];
            $userId = $job->user_id ?? 'anonymous';
            $jobFolder = "generations/{$userId}/{$job->queue_job_id}";

            // Check if folder exists
            if (!Storage::disk('public')->exists($jobFolder)) {
                continue;
            }

            $jobsProcessed++;

            if ($dryRun) {
                $files = Storage::disk('public')->files($jobFolder);
                $size = collect($files)->sum(fn ($f) => Storage::disk('public')->size($f));
                $this->line("  Would delete: {$jobFolder} ({$this->formatBytes($size)}, " . count($files) . " files)");
                $filesDeleted += count($files);
                $bytesFreed += $size;
                continue;
            }

            try {
                // Get file sizes before deleting
                $files = Storage::disk('public')->files($jobFolder);
                $size = collect($files)->sum(fn ($f) => Storage::disk('public')->size($f));

                // Delete the entire job folder
                Storage::disk('public')->deleteDirectory($jobFolder);

                $filesDeleted += count($files);
                $bytesFreed += $size;

                // Update the result to indicate assets were cleaned up
                // Keep the metadata but mark URLs as expired
                $updatedAssets = collect($assets)->map(function ($asset) {
                    $updated = $asset;
                    $updated['url'] = null;
                    $updated['thumbnail'] = null;
                    $updated['cleaned_up'] = true;
                    // Keep original vendor URLs as reference (they're likely expired too)
                    return $updated;
                })->toArray();

                $result['assets'] = $updatedAssets;
                $result['asset_urls'] = [];
                $result['assets_stored_locally'] = false;
                $result['cleaned_up_at'] = now()->toDateTimeString();

                $job->update(['result' => $result]);

            } catch (\Exception $e) {
                $errors++;
                $this->error("  Error cleaning {$jobFolder}: {$e->getMessage()}");
                Log::error('generations:cleanup error', [
                    'job_id' => $job->id,
                    'folder' => $jobFolder,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Clean up empty user directories
        if (!$dryRun) {
            $this->cleanEmptyDirectories();
        }

        $this->newLine();
        $this->info("Done!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Jobs processed', $jobsProcessed],
                ['Files deleted', $filesDeleted],
                ['Space freed', $this->formatBytes($bytesFreed)],
                ['Errors', $errors],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to actually delete.');
        }

        return self::SUCCESS;
    }

    /**
     * Remove empty user directories under generations/.
     */
    private function cleanEmptyDirectories(): void
    {
        $generationsDir = 'generations';

        if (!Storage::disk('public')->exists($generationsDir)) {
            return;
        }

        $userDirs = Storage::disk('public')->directories($generationsDir);

        foreach ($userDirs as $userDir) {
            $remaining = Storage::disk('public')->allFiles($userDir);
            if (empty($remaining)) {
                Storage::disk('public')->deleteDirectory($userDir);
                $this->line("  Removed empty directory: {$userDir}");
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
