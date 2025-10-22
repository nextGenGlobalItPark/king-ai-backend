<?php

namespace App\Strategies;

use App\Contracts\PollStrategyInterface;
use Illuminate\Support\Facades\Log;

class KlingPollStrategy implements PollStrategyInterface
{
    public function buildEndpoint(string $generateEndpoint, string $taskId): string
    {
        // Hardcoded Kling base (independent of generate; from API docs)
        return 'https://api.kling.ai/v1/tasks/' . $taskId;
    }

    public function parseResponse(array $response): array
    {
        $status = $response['status'] ?? 'unknown';

        if ($status === 'succeeded' || $status === 'done') {
            return [
                'status' => 'completed',
                'result' => ['video_url' => $response['video_url'] ?? $response['output'] ?? []],
                'progress' => 100.0,
            ];
        } elseif ($status === 'failed' || $status === 'error') {
            return [
                'status' => 'failed',
                'error' => $response['error_message'] ?? $response['error'] ?? 'Unknown error',
                'progress' => 0.0,
            ];
        }

        return [
            'status' => 'processing',
            'progress' => ($response['progress'] ?? 0) * 100,  // Assume 0-1 → 0-100
        ];
    }
}