<?php

namespace App\Strategies;

use App\Contracts\PollStrategyInterface;
use App\Models\VendorStatusMapping;
use Illuminate\Support\Facades\Log;

class ViduPollStrategy implements PollStrategyInterface
{
    private ?VendorStatusMapping $statusMapping = null;

    public function __construct(int $dataSourceId)
    {
        // Load status mapping for this vendor
        $this->statusMapping = VendorStatusMapping::where('data_source_id', $dataSourceId)->first();
    }

    public function buildEndpoint(string $generateEndpoint, string $taskId): string
    {
        // Vidu-specific: From '/ent/v2/text2video' → '/ent/v2/tasks/{taskId}/creations'
        $parts = explode('/', rtrim($generateEndpoint, '/'));
        array_pop($parts);  // Remove last (e.g., 'text2video')
        $parts[] = 'tasks';
        $parts[] = $taskId;
        $parts[] = 'creations';

        $endpoint = implode('/', $parts);
        Log::debug('Built Vidu poll endpoint', ['endpoint' => $endpoint]);
        return $endpoint;
    }

    public function parseResponse(array $response, array $responseMapping = []): array
    {
        // Extract status using response_mapping
        $statusPath = $responseMapping['status_path'] ?? $responseMapping['status'] ?? 'data.state';
        $vendorStatus = data_get($response, $statusPath, 'unknown');

        // Map vendor status to internal status
        $internalStatus = $this->mapStatus($vendorStatus);

        Log::debug('Vidu status mapped', [
            'vendor_status' => $vendorStatus,
            'internal_status' => $internalStatus,
            'status_path' => $statusPath
        ]);

        if ($internalStatus === 'completed') {
            return $this->buildCompletedResponse($response, $responseMapping);
        }

        if ($internalStatus === 'failed') {
            return $this->buildFailedResponse($response, $responseMapping);
        }

        // Processing - try to get progress
        $progressPath = $responseMapping['progress_path'] ?? 'data.progress';
        $progress = data_get($response, $progressPath);

        if ($progress !== null && is_numeric($progress)) {
            // If progress is 0-1, convert to 0-100
            $progress = $progress <= 1 ? $progress * 100 : $progress;
        }

        return [
            'status' => 'processing',
            'progress' => $progress,
        ];
    }

    /**
     * Build completed response with full result structure
     */
    private function buildCompletedResponse(array $response, array $responseMapping): array
    {
        $resultPath = $responseMapping['result_path'] ?? $responseMapping['result'] ?? 'data.creations';
        $assetArrayPath = $responseMapping['asset_array_path'] ?? $responseMapping['asset_array'] ?? 'data.creations.0.videos';
        $assetUrlPath = $responseMapping['asset_url_path'] ?? 'url';
        $assetIdPath = $responseMapping['asset_id_path'] ?? $responseMapping['asset_id'] ?? 'id';

        // Get creations array
        $creations = data_get($response, $resultPath, []);

        // Get videos from first creation
        $videos = data_get($response, $assetArrayPath, []);

        if (empty($creations)) {
            Log::warning('Vidu success but empty creations', [
                'task_id' => data_get($response, 'data.task_id', 'unknown')
            ]);

            return [
                'status' => 'completed',
                'result' => [
                    'assets' => [],
                    'asset_urls' => [],
                ],
                'progress' => 100.0,
            ];
        }

        // Extract asset URLs and build assets array
        $assetUrls = [];
        $processedAssets = [];

        foreach ($videos as $video) {
            $assetData = [
                'id' => data_get($video, $assetIdPath),
                'url' => data_get($video, $assetUrlPath),
            ];

            // Add any other fields from the video
            foreach ($video as $key => $value) {
                if (!isset($assetData[$key])) {
                    $assetData[$key] = $value;
                }
            }

            $processedAssets[] = $assetData;

            if ($url = data_get($video, $assetUrlPath)) {
                $assetUrls[] = $url;
            }
        }

        Log::debug('Vidu completed response built', [
            'asset_count' => count($processedAssets),
            'url_count' => count($assetUrls)
        ]);

        return [
            'status' => 'completed',
            'result' => [
                'assets' => $processedAssets,
                'asset_urls' => $assetUrls,
                'creations' => $creations, // Keep full creations for reference
            ],
            'progress' => 100.0,
        ];
    }

    /**
     * Build failed response with error details
     */
    private function buildFailedResponse(array $response, array $responseMapping): array
    {
        $errorMessagePath = $responseMapping['error_message_path'] ??
            $responseMapping['error_message'] ??
            'data.err_msg';

        $errorCodePath = $responseMapping['error_code_path'] ??
            $responseMapping['error_code'] ??
            'code';

        $errorMessage = data_get($response, $errorMessagePath) ??
            data_get($response, 'data.error') ??
            data_get($response, 'data.message') ??
            'Unknown Vidu error';

        $errorCode = data_get($response, $errorCodePath);

        $error = $errorMessage;
        if ($errorCode) {
            $error = "[Code: {$errorCode}] {$errorMessage}";
        }

        Log::debug('Vidu failed response', [
            'error' => $error,
            'error_path' => $errorMessagePath
        ]);

        return [
            'status' => 'failed',
            'error' => $error,
            'progress' => 0.0,
        ];
    }

    /**
     * Map vendor status to internal status using database mapping
     */
    private function mapStatus(string $vendorStatus): string
    {
        if (!$this->statusMapping) {
            // Fallback if no mapping exists
            return match ($vendorStatus) {
                'succeed', 'success', 'completed', 'succeeded' => 'completed',
                'failed', 'error' => 'failed',
                'pending', 'processing', 'in_progress' => 'processing',
                default => 'processing',
            };
        }

        return $this->statusMapping->mapStatus($vendorStatus);
    }
}