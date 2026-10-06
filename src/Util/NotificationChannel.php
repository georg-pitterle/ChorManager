<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die Wege, auf denen eine Benachrichtigung ankommt.
 *
 * Jeder Anlass aus `NotificationType` geht über beide Kanäle; abbestellen
 * lässt sich jeder Kanal für sich.
 */
final class NotificationChannel
{
    public const MAIL = 'mail';
    public const IN_APP = 'in_app';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::MAIL, self::IN_APP];
    }
}
