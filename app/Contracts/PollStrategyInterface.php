<?php

namespace App\Contracts;

interface PollStrategyInterface
{
    /**
     * Build the polling endpoint URL.
     *
     * @param string $statusCheckEndpoint The status_check_endpoint from the config
     * @param string $taskId The task ID from the generation response
     * @return string The full polling URL
     */
    public function buildEndpoint(string $statusCheckEndpoint, string $taskId): string;

    /**
     * Get the HTTP method for polling (GET, POST, etc.)
     */
    public function getPollingMethod(): string;

    /**
     * Parse and normalize the API response to a standard status array.
     *
     * Expected return:
     * [
     *   'status' => 'completed|processing|failed',
     *   'result' => ['assets' => [...], 'asset_urls' => [...]],
     *   'error' => 'string or null',
     *   'progress' => float or null (0-100)
     * ]
     */
    public function parseResponse(array $response, array $responseMapping = []): array;
}
