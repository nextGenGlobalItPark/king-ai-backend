<?php

namespace App\Strategies;

use App\Contracts\PollStrategyInterface;

class KlingPollStrategy implements PollStrategyInterface
{
    public function buildEndpoint(string $generateEndpoint, string $taskId): string
    {
        // Kling-specific: Assume '/api/v1/tasks/{taskId}' (independent of generate endpoint)
        return str_replace('/generate', '/tasks/' . $taskId, $generateEndpoint);  // Or hardcode: 'https://api.kling.ai/v1/tasks/' . $taskId
    }

    public function parseResponse(array $response): array
    {
        $status = $response['status'] ?? 'unknown';
        if ($status === 'done') {
            return [
                'status' => 'completed',
                'result' => $response['output'] ?? [],
            ];
        } elseif ($status === 'error') {
            return [
                'status' => 'failed',
                'error' => $response['error_message'] ?? 'Unknown error',
            ];
        }

        return [
            'status' => 'processing',
            'progress' => $response['progress'] ?? null,
        ];
    }
}