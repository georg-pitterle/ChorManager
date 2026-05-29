<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarSubscriptionToken;

class CalendarSubscriptionService
{
    public function getOrCreateTokenForUser(int $userId): string
    {
        $subscription = CalendarSubscriptionToken::where('user_id', $userId)->first();
        if ($subscription) {
            return (string) $subscription->token;
        }

        $token = bin2hex(random_bytes(32));

        CalendarSubscriptionToken::create([
            'user_id' => $userId,
            'token' => $token,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    public function findByToken(string $token): ?CalendarSubscriptionToken
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return CalendarSubscriptionToken::where('token', $token)->first();
    }
}
