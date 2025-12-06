<?php

namespace App\Strategies\AuthStrategy;

use App\Contracts\AuthStrategyInterface;
use App\Models\AiFeatureConfig;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

class JwtAuthStrategy  implements AuthStrategyInterface
{
    public function generateAuthHeader(AiFeatureConfig $config): string
    {
        // Read Access Key and Secret Key from environment
        $accessKey = env($config->auth_value_from_env); // KLING_ACCESS_KEY
        $secretKey = env($config->auth_secret_key_env); // KLING_SECRET_KEY

        if (empty($accessKey) || empty($secretKey)) {
            throw new \RuntimeException(
                "Kling credentials not configured. Missing: " .
                ($accessKey ? '' : $config->auth_value_from_env) .
                ($secretKey ? '' : ' or ' . $config->auth_secret_key_env)
            );
        }

        // Generate JWT token
        $token = $this->encodeJwtToken(
            $accessKey,
            $secretKey,
            $config->jwt_expiry_seconds ?? 1800,
            $config->jwt_nbf_offset_seconds ?? 5
        );
        Log::debug($token);

        Log::debug('Generated Kling JWT token', [
            'expires_in' => $config->jwt_expiry_seconds,
            'token_preview' => substr($token, 0, 20) . '...'
        ]);

        // Return "Bearer <token>"
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