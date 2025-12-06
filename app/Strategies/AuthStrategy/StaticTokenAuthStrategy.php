<?php

namespace App\Strategies\AuthStrategy;

use App\Contracts\AuthStrategyInterface;
use App\Models\AiFeatureConfig;
use Illuminate\Support\Str;

class StaticTokenAuthStrategy  implements AuthStrategyInterface
{
    public function generateAuthHeader(AiFeatureConfig $config): string
    {
        // Read static token from environment
        $token = env($config->auth_value_from_env);

        if (empty($token)) {
            throw new \RuntimeException(
                "Environment variable '{$config->auth_value_from_env}' is not set"
            );
        }

        // Return formatted header value
        // e.g., "Token sk_vidu_xxxx" or "Bearer sk_vidu_xxxx"
        return Str::ucfirst($config->auth_type) . ' ' . $token;
    }
}