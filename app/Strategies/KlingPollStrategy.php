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
        return $generateEndpoint.'/' . $taskId;
    }

    public function parseResponse(array $response, array $responseMapping = []): array
    {
        // Extract status using response_mapping
        $vendorStatus = data_get($response, $responseMapping['status_path'] ?? 'data.task_status', 'unknown');

        // Map vendor status to internal status
        $internalStatus = $this->mapStatus($vendorStatus);

        Log::debug('Kling status mapped', [
            'vendor_status' => $vendorStatus,
            'internal_status' => $internalStatus
        ]);

        if ($internalStatus === 'completed') {
            // Extract assets using response_mapping
            $assetsPath = $responseMapping['asset_array_path'] ?? 'data.task_result.videos';
            $assets = data_get($response, $assetsPath, []);

            $assetUrlPath = $responseMapping['asset_url_path'] ?? 'url';
            $assetIdPath = $responseMapping['asset_id_path'] ?? 'id';

            $assetUrls = array_map(fn($asset) => $asset[$assetUrlPath] ?? null, $assets);

            return [
                'status' => 'completed',
                'result' => [
                    'asset_urls' => array_filter($assetUrls),
                    'assets' => $assets,
                ],
                'progress' => 100.0,
            ];
        }

        if ($internalStatus === 'failed') {
            $errorPath = $responseMapping['status_message_path'] ?? 'data.task_status_msg';
            $errorMessage = data_get($response, $errorPath, 'Unknown error');

            return [
                'status' => 'failed',
                'error' => $errorMessage,
                'progress' => 0.0,
            ];
        }

        // Processing
        return [
            'status' => 'processing',
            'progress' => null,
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