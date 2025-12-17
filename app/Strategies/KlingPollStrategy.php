<?php

namespace App\Strategies;

use App\Contracts\PollStrategyInterface;
use App\Models\VendorStatusMapping;
use Illuminate\Support\Facades\Log;

class KlingPollStrategy implements PollStrategyInterface
{
    private ?VendorStatusMapping $statusMapping = null;

    public function __construct(int $dataSourceId)
    {
        // Load status mapping for this vendor
        $this->statusMapping = VendorStatusMapping::where('data_source_id', $dataSourceId)->first();
    }

    public function buildEndpoint(string $generateEndpoint, string $taskId): string
    {
        return $generateEndpoint . '/' . $taskId;
    }

    public function parseResponse(array $response, array $responseMapping = []): array
    {
        // Extract status using response_mapping
        $statusPath = $responseMapping['status_path'] ?? $responseMapping['status'] ?? 'data.task_status';
        $vendorStatus = data_get($response, $statusPath, 'unknown');

        // Map vendor status to internal status
        $internalStatus = $this->mapStatus($vendorStatus);

        Log::debug('Kling status mapped', [
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

        // Processing
        return [
            'status' => 'processing',
            'progress' => null,
        ];
    }

    /**
     * Build completed response with full result structure
     */
    private function buildCompletedResponse(array $response, array $responseMapping): array
    {
        // Extract all relevant paths from response_mapping
        $assetArrayPath = $responseMapping['asset_array_path'] ?? $responseMapping['asset_array'] ?? 'data.task_result.videos';
        $assetUrlPath = $responseMapping['asset_url_path'] ?? 'url';
        $assetIdPath = $responseMapping['asset_id_path'] ?? $responseMapping['asset_id'] ?? 'id';
        $videoDurationPath = $responseMapping['video_duration_path'] ?? 'duration';
        $resultPath = $responseMapping['result_path'] ?? $responseMapping['result'] ?? 'data.task_result';

        // Get the full result object
        $fullResult = data_get($response, $resultPath, []);

        // Get assets array
        $assets = data_get($response, $assetArrayPath, []);

        // Extract asset URLs and build assets array with all fields
        $assetUrls = [];
        $processedAssets = [];

        foreach ($assets as $asset) {
            $assetData = [
                'id' => data_get($asset, $assetIdPath),
                'url' => data_get($asset, $assetUrlPath),
            ];

            // Add duration if available
            if ($duration = data_get($asset, $videoDurationPath)) {
                $assetData['duration'] = $duration;
            }

            // Add any other fields from the asset
            foreach ($asset as $key => $value) {
                if (!isset($assetData[$key])) {
                    $assetData[$key] = $value;
                }
            }

            $processedAssets[] = $assetData;

            if ($url = data_get($asset, $assetUrlPath)) {
                $assetUrls[] = $url;
            }
        }

        Log::debug('Kling completed response built', [
            'asset_count' => count($processedAssets),
            'url_count' => count($assetUrls)
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
     * Build failed response with error details
     */
    private function buildFailedResponse(array $response, array $responseMapping): array
    {
        // Try multiple paths for error message
        $errorMessagePath = $responseMapping['error_message_path'] ??
            $responseMapping['error_message'] ??
            $responseMapping['status_message_path'] ??
            $responseMapping['status_message'] ??
            'data.task_status_msg';

        $errorCodePath = $responseMapping['error_code_path'] ??
            $responseMapping['error_code'] ??
            'code';

        $errorMessage = data_get($response, $errorMessagePath, 'Unknown error');
        $errorCode = data_get($response, $errorCodePath);

        $error = $errorMessage;
        if ($errorCode) {
            $error = "[Code: {$errorCode}] {$errorMessage}";
        }

        Log::debug('Kling failed response', [
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
                'succeed', 'success', 'completed' => 'completed',
                'failed', 'error' => 'failed',
                default => 'processing',
            };
        }

        return $this->statusMapping->mapStatus($vendorStatus);
    }
}