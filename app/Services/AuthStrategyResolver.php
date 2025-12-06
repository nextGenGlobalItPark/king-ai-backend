<?php

namespace App\Services;

use App\Contracts\AuthStrategyInterface;
use App\Models\AiFeatureConfig;
use App\Strategies\AuthStrategy\JwtAuthStrategy;
use App\Strategies\AuthStrategy\StaticTokenAuthStrategy;
use InvalidArgumentException;

class AuthStrategyResolver
{
    public function resolve(AiFeatureConfig $config): AuthStrategyInterface
    {
        return match (strtolower($config->auth_type)) {
            'token', 'bearer', 'static_token' => new StaticTokenAuthStrategy(),
            'jwt' => new JwtAuthStrategy(),
            default => throw new InvalidArgumentException(
                "Unsupported auth type: {$config->auth_type}"
            )
        };
    }
}