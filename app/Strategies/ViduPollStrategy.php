<?php

namespace App\Strategies;

use App\Contracts\PollStrategyInterface;
use Illuminate\Support\Facades\Log;

class ViduPollStrategy implements PollStrategyInterface
{
    public function buildEndpoint(string $generateEndpoint, string $taskId): string
    {
        // Vidu-specific: From '/ent/v2/text2video' → '/ent/v2/tasks/{taskId}/creations'
        $parts = explode('/', rtrim($generateEndpoint, '/'));
        array_pop($parts);  // Remove 'text2video'
        $parts[] = 'tasks';
        $parts[] = $taskId;
        $parts[] = 'creations';  // Vidu requires /creations for details

        $endpoint = implode('/', $parts);
        Log::debug('Built Vidu poll endpoint', ['endpoint' => $endpoint]);  // Optional: For verification
        return $endpoint;
    }

    public function parseResponse(array $response): array
    {
        // Vidu wraps in 'data'; fallback to direct keys
        $data = $response['data'] ?? $response;
        $state = $data['state'] ?? 'unknown';
        Log::debug('Vidu parsed state', ['raw_state' => $state]);  // For debugging

        if ($state === 'success' || $state === 'succeeded') {
            $creations = $data['creations'] ?? [];
            $firstCreation = $creations[0] ?? null ?? [];
            if ($firstCreation) {
                return [
                    'status' => 'complete',
                    'result' => $data
                ];
            }
            // Fallback if no creations
            return [
                'status' => 'complete',
                'result' => ['message' => 'Task succeeded but no creations found'],
            ];
        } elseif ($state === 'failed' || $state === 'error') {
            return [
                'status' => 'failed',
                'error' => $data['err_msg'] ?? $data['error'] ?? $data['message'] ?? 'Unknown Vidu error',
            ];
        } elseif (in_array($state, ['pending', 'processing', 'in_progress'])) {
            return [
                'status' => 'processing',
                'result' => null,
            ];
        }

        // Unknown: Treat as processing for safety
        return [
            'status' => 'processing',
            'result' => null,
        ];
    }
}