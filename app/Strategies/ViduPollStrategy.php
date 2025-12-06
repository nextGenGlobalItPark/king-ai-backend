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
        array_pop($parts);  // Remove last (e.g., 'template')
        $parts[] = 'tasks';
        $parts[] = $taskId;
        $parts[] = 'creations';  // For result details

        $endpoint = implode('/', $parts);
        Log::debug('Built Vidu poll endpoint', ['endpoint' => $endpoint]);
        return $endpoint;
    }

    public function parseResponse(array $response): array
    {
        // Vidu wraps in 'data'
        $data = $response['data'] ?? $response;
        $state = $data['state'] ?? $data['status'] ?? 'unknown';  // Flexible
        Log::debug('Vidu parsed state', ['raw_state' => $state]);

        if ($state === 'success' || $state === 'succeeded' || $state === 'completed') {
            $creations = $data['creations'] ?? [];
            $firstCreation = $creations[0] ?? [];
            $videoUrl = $firstCreation['videos'][0]['url'] ?? $data['video_url'] ?? null;  // Common paths

            if (empty($creations)) {
                Log::warning('Vidu success but empty creations', ['task_id' => $data['task_id'] ?? 'unknown']);
                return [
                    'status' => 'completed',
                    'result' => ['message' => 'Task succeeded but no video found'],
                    'progress' => 100.0,
                ];
            }

            return [
                'status' => 'completed',
                'result' => ['video_url' => $videoUrl, 'creations' => $creations],
                'progress' => 100.0,
            ];
        } elseif ($state === 'failed' || $state === 'error') {
            return [
                'status' => 'failed',
                'error' => $data['err_msg'] ?? $data['error'] ?? $data['message'] ?? 'Unknown Vidu error',
                'progress' => 0.0,
            ];
        } elseif (in_array($state, ['pending', 'processing', 'in_progress'])) {
            return [
                'status' => 'processing',
                'progress' => ($data['progress'] ?? 0) * 100 ?? null,
            ];
        }

        // Unknown: Treat as processing
        return [
            'status' => 'processing',
            'progress' => null,
        ];
    }
}