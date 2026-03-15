<?php

namespace App\Strategies;

use App\Contracts\PollStrategyInterface;
use App\Models\VendorStatusMapping;
use Illuminate\Support\Facades\Log;

/**
 * Generic poll strategy that works for ANY vendor.
 *
 * All behavior is driven by the response_mapping config — no vendor-specific code needed.
 * Replaces KlingPollStrategy, ViduPollStrategy, and any future vendor strategies.
 *
 * Expected response_mapping structure:
 * {
 *     "generation": {
 *         "task_id_path": "data.task_id",
 *         "error_code_path": "code",
 *         "error_message_path": "message",
 *         "success_codes": [0, 200]
 *     },
 *     "polling": {
 *         "method": "GET",
 *         "url_template": "{status_check_endpoint}/{task_id}",
 *         "status_path": "data.task_status",
 *         "progress_path": "data.progress",
 *         "error_code_path": "code",
 *         "error_message_path": "data.task_status_msg"
 *     },
 *     "result": {
 *         "result_path": "data.task_result",
 *         "asset_array_path": "data.task_result.videos",
 *         "asset_url_path": "url",
 *         "asset_id_path": "id",
 *         "duration_path": "duration",
 *         "thumbnail_path": "cover_url"
 *     }
 * }
 */
class GenericPollStrategy implements PollStrategyInterface
{
    private ?VendorStatusMapping $statusMapping = null;
    private array $responseMapping;

    public function __construct(int $dataSourceId, array $responseMapping = [])
    {
        $this->statusMapping = VendorStatusMapping::where('data_source_id', $dataSourceId)->first();
        $this->responseMapping = $responseMapping;
    }

    /**
     * Build the polling endpoint using the configurable url_template.
     *
     * Supports placeholders:
     *   {status_check_endpoint} — the status_check_endpoint from config
     *   {endpoint} — the generation endpoint from config
     *   {task_id} — the task ID from the generation response
     *
     * Examples:
     *   "{status_check_endpoint}/{task_id}"          → Kling style
     *   "{endpoint}/../tasks/{task_id}/creations"     → Vidu style (not needed, just use status_check_endpoint)
     */
    public function buildEndpoint(string $statusCheckEndpoint, string $taskId): string
    {
        $pollingConfig = $this->responseMapping['polling'] ?? [];
        $urlTemplate = $pollingConfig['url_template'] ?? '{status_check_endpoint}/{task_id}';

        $endpoint = str_replace(
            ['{status_check_endpoint}', '{task_id}'],
            [rtrim($statusCheckEndpoint, '/'), $taskId],
            $urlTemplate
        );

        Log::debug('Built poll endpoint from template', [
            'template' => $urlTemplate,
            'endpoint' => $endpoint,
        ]);

        return $endpoint;
    }

    /**
     * Get the HTTP method for polling (configurable, defaults to GET).
     */
    public function getPollingMethod(): string
    {
        return $this->responseMapping['polling']['method'] ?? 'GET';
    }

    /**
     * Parse and normalize the API response to a standard status array.
     */
    public function parseResponse(array $response, array $responseMapping = []): array
    {
        // Use instance mapping, allow override from parameter for backward compat
        $mapping = !empty($responseMapping) ? $responseMapping : $this->responseMapping;
        $pollingConfig = $mapping['polling'] ?? $mapping;
        $resultConfig = $mapping['result'] ?? $mapping;

        // Extract status
        $statusPath = $pollingConfig['status_path'] ?? 'data.task_status';
        $vendorStatus = data_get($response, $statusPath, 'unknown');
        $internalStatus = $this->mapStatus($vendorStatus);

        Log::debug('Status mapped', [
            'vendor_status' => $vendorStatus,
            'internal_status' => $internalStatus,
            'status_path' => $statusPath,
        ]);

        if ($internalStatus === 'completed') {
            return $this->buildCompletedResponse($response, $resultConfig);
        }

        if ($internalStatus === 'failed') {
            return $this->buildFailedResponse($response, $pollingConfig);
        }

        // Processing — extract progress if available
        $progressPath = $pollingConfig['progress_path'] ?? null;
        $progress = null;

        if ($progressPath) {
            $progress = data_get($response, $progressPath);
            if ($progress !== null && is_numeric($progress)) {
                // Normalize: if 0-1 scale, convert to 0-100
                $progress = $progress <= 1 && $progress > 0 ? $progress * 100 : (float)$progress;
            }
        }

        return [
            'status' => 'processing',
            'progress' => $progress,
        ];
    }

    /**
     * Build completed response using result mapping config.
     */
    private function buildCompletedResponse(array $response, array $resultConfig): array
    {
        $assetArrayPath = $resultConfig['asset_array_path'] ?? 'data.task_result.videos';
        $assetUrlPath = $resultConfig['asset_url_path'] ?? 'url';
        $assetIdPath = $resultConfig['asset_id_path'] ?? 'id';
        $durationPath = $resultConfig['duration_path'] ?? 'duration';
        $thumbnailPath = $resultConfig['thumbnail_path'] ?? null;

        $assets = data_get($response, $assetArrayPath, []);

        if (empty($assets)) {
            Log::warning('Completed but no assets found', [
                'asset_array_path' => $assetArrayPath,
            ]);

            return [
                'status' => 'completed',
                'result' => ['assets' => [], 'asset_urls' => []],
                'progress' => 100.0,
            ];
        }

        $processedAssets = [];
        $assetUrls = [];

        foreach ($assets as $asset) {
            $assetData = [
                'id' => data_get($asset, $assetIdPath),
                'url' => data_get($asset, $assetUrlPath),
                'duration' => data_get($asset, $durationPath),
            ];

            // Add thumbnail if path configured and value exists
            if ($thumbnailPath) {
                $thumbnail = data_get($asset, $thumbnailPath);
                if ($thumbnail) {
                    $assetData['thumbnail'] = $thumbnail;
                }
            }

            $processedAssets[] = $assetData;

            if ($url = $assetData['url']) {
                $assetUrls[] = $url;
            }
        }

        Log::debug('Completed response built', [
            'asset_count' => count($processedAssets),
            'url_count' => count($assetUrls),
        ]);

        return [
            'status' => 'completed',
            'result' => [
                'assets' => $processedAssets,
                'asset_urls' => $assetUrls,
            ],
            'progress' => 100.0,
        ];
    }

    /**
     * Build failed response using polling mapping config.
     */
    private function buildFailedResponse(array $response, array $pollingConfig): array
    {
        $errorMessagePath = $pollingConfig['error_message_path'] ?? 'message';
        $errorCodePath = $pollingConfig['error_code_path'] ?? 'code';

        $errorMessage = data_get($response, $errorMessagePath, 'Unknown error');
        $errorCode = data_get($response, $errorCodePath);

        $error = $errorMessage;
        if ($errorCode) {
            $error = "[Code: {$errorCode}] {$errorMessage}";
        }

        Log::debug('Failed response built', [
            'error' => $error,
            'error_message_path' => $errorMessagePath,
        ]);

        return [
            'status' => 'failed',
            'error' => $error,
            'progress' => 0.0,
        ];
    }

    /**
     * Map vendor status to internal status using database mapping.
     * Falls back to common status string matching.
     */
    private function mapStatus(string $vendorStatus): string
    {
        if ($this->statusMapping) {
            return $this->statusMapping->mapStatus($vendorStatus);
        }

        // Universal fallback
        $lower = strtolower($vendorStatus);
        return match (true) {
            in_array($lower, ['succeed', 'success', 'completed', 'succeeded', 'done', 'finished']) => 'completed',
            in_array($lower, ['failed', 'error', 'failure', 'rejected', 'cancelled', 'canceled']) => 'failed',
            default => 'processing',
        };
    }
}
