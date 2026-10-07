<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Ein Knoten der Speicherübersicht. Der Schlüssel ist stabil und der Andockpunkt
 * für spätere Aufräum-Funktionen; das Label ist reine Anzeige.
 *
 * `breakdown` markiert eine Aufschlüsselung derselben Bytes nach einem anderen
 * Merkmal (Teamordner). Sie zählt nicht in die Summe des Elternknotens.
 */
final class StorageUsageNode
{
    public const ERROR_UNAVAILABLE = 'nicht ermittelbar';

    /**
     * @param list<StorageUsageNode> $children
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $bytes,
        public readonly ?int $count = null,
        public readonly array $children = [],
        public readonly ?string $error = null,
        public readonly ?int $quotaBytes = null,
        public readonly bool $breakdown = false
    ) {
    }

    /**
     * @param list<StorageUsageNode> $children
     */
    public static function sum(string $key, string $label, array $children, ?int $count = null): self
    {
        $bytes = 0;
        foreach ($children as $child) {
            if (!$child->breakdown) {
                $bytes += $child->bytes;
            }
        }

        return new self($key, $label, $bytes, $count, $children);
    }

    public static function failed(string $key, string $label): self
    {
        return new self($key, $label, 0, null, [], self::ERROR_UNAVAILABLE);
    }

    public function child(string $key): ?self
    {
        foreach ($this->children as $child) {
            if ($child->key === $key) {
                return $child;
            }
        }

        return null;
    }
}
