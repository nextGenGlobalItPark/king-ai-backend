<?php

namespace App\Services;

interface PushNotificationServiceInterface
{
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void;
}