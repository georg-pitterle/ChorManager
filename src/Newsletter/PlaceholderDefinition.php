<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Models\User;

/**
 * Ein einzelner Newsletter-Platzhalter samt Metadaten für Auswahlliste und Hilfe.
 *
 * Der Scope legt fest, was zum Auflösen nötig ist: "recipient" braucht eine Person,
 * "newsletter" nur den Datensatz, "global" gar nichts.
 */
final class PlaceholderDefinition
{
    public const SCOPE_RECIPIENT = 'recipient';
    public const SCOPE_NEWSLETTER = 'newsletter';
    public const SCOPE_GLOBAL = 'global';

    /** @var callable(RenderContext, ?User): string */
    private $resolver;

    /**
     * @param callable(RenderContext, ?User): string $resolver
     * @param bool $isRawHtml true nur für Platzhalter, die selbst sicheres Markup erzeugen.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $scope,
        public readonly string $example,
        callable $resolver,
        public readonly bool $isRawHtml = false
    ) {
        $this->resolver = $resolver;
    }

    public function resolve(RenderContext $context, ?User $recipient): string
    {
        return (string) ($this->resolver)($context, $recipient);
    }
}
