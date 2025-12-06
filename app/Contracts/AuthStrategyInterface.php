<?php

namespace App\Contracts;

use App\Models\AiFeatureConfig;

interface AuthStrategyInterface
{
    /**
     * Generate the Authorization header value
     *
     * @param AiFeatureConfig $config
     * @return string
     */
    public function generateAuthHeader(AiFeatureConfig $config): string;
}