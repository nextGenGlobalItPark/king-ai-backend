<?php

namespace App\Services;

use App\Contracts\PollStrategyInterface;
use App\Strategies\GenericPollStrategy;

class PollStrategyResolver
{
    /**
     * Resolve the poll strategy for a given data source.
     *
     * Now always returns GenericPollStrategy — all vendor-specific behavior
     * is driven by the response_mapping config. No need for KlingPollStrategy,
     * ViduPollStrategy, or any future vendor-specific classes.
     *
     * @param int $dataSourceId
     * @param array $responseMapping The response_mapping from AiFeatureConfig
     * @return PollStrategyInterface
     */
    public function resolve(int $dataSourceId, array $responseMapping = []): PollStrategyInterface
    {
        return new GenericPollStrategy($dataSourceId, $responseMapping);
    }
}
