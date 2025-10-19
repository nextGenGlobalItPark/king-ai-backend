<?php

namespace App\Contracts;

interface PollStrategyInterface
{
    /**
     * Build the polling endpoint for the given generate endpoint and task ID.
     */
    public function buildEndpoint(string $generateEndpoint, string $taskId): string;

    /**
     * Parse and normalize the API response to a standard status array.
     * Expected return: ['status' => 'completed|processing|failed', 'result' => [...], 'error' => '...', 'progress' => ?float]
     */
    public function parseResponse(array $response): array;
}