<?php

namespace App\Strategies\AuthStrategy;

use App\Contracts\AuthStrategyInterface;
use App\Models\AiFeatureConfig;
use Illuminate\Support\Str;

class StaticTokenAuthStrategy implements AuthStrategyInterface
{
    public function generateAuthHeader(AiFeatureConfig $config): string
    {
        // Use config() helper which works after config:cache (env() returns null after caching)
        $token = config('vendor-credentials.' . $config->auth_value_from_env);

        if (empty($token)) {
            throw new \RuntimeException(
                "Environment variable '{$config->auth_value_from_env}' is not set"
            );
        }

        return Str::ucfirst($config->auth_type) . ' ' . $token;
    }
}