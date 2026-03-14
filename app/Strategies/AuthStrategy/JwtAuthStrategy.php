<?php

namespace App\Strategies\AuthStrategy;

use App\Contracts\AuthStrategyInterface;
use App\Models\AiFeatureConfig;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

class JwtAuthStrategy implements AuthStrategyInterface
{
    public function generateAuthHeader(AiFeatureConfig $config): string
    {
        // Use config() helper which works after config:cache (env() returns null after caching)
        $accessKey = config('vendor-credentials.' . $config->auth_value_from_env);
        $secretKey = config('vendor-credentials.' . $config->auth_secret_key_env);

        if (empty($accessKey) || empty($secretKey)) {
            $missing = [];
            if (empty($accessKey)) $missing[] = $config->auth_value_from_env;
            if (empty($secretKey)) $missing[] = $config->auth_secret_key_env;
            throw new \RuntimeException(
                "JWT credentials not configured. Missing env: " . implode(', ', $missing)
            );
        }

        $token = $this->encodeJwtToken(
            $accessKey,
            $secretKey,
            $config->jwt_expiry_seconds ?? 1800,
            $config->jwt_nbf_offset_seconds ?? 5
        );

        Log::debug('Generated JWT token', [
            'expires_in' => $config->jwt_expiry_seconds,
            'token_preview' => substr($token, 0, 20) . '...'
        ]);

        return 'Bearer ' . $token;
    }

    /**
     * Encode JWT token following Kling's specification
     *
     * @param string $accessKey
     * @param string $secretKey
     * @param int $expirySeconds
     * @param int $nbfOffset
     * @return string
     */
    private function encodeJwtToken(
        string $accessKey,
        string $secretKey,
        int $expirySeconds = 1800,
        int $nbfOffset = 5
    ): string {
        $currentTime = time();

        $payload = [
            'iss' => $accessKey,                    // Issuer (Access Key)
            'exp' => $currentTime + $expirySeconds, // Expiration time (30 min from now)
            'nbf' => $currentTime - $nbfOffset      // Not before (5 seconds ago)
        ];

        $headers = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        return JWT::encode($payload, $secretKey, 'HS256', null, $headers);
    }
}