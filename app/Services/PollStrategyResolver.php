<?php

namespace App\Services;

use App\Contracts\PollStrategyInterface;
use App\Models\DataSource;
use App\Strategies\ViduPollStrategy;
use App\Strategies\KlingPollStrategy;
use InvalidArgumentException;

class PollStrategyResolver
{
    public function resolve(int $dataSourceId): PollStrategyInterface
    {
        $vendorName = DataSource::findOrFail($dataSourceId)->name;

        return match (strtolower($vendorName)) {
            'vidu' => new ViduPollStrategy(),
            'kling' => new KlingPollStrategy(),
            default => throw new InvalidArgumentException("No poll strategy for vendor: {$vendorName}. Add a strategy or use generic."),
        };
    }
}