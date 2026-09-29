<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Die Summe der angehängten Dateien übersteigt, was verbreitete Postfächer
 * annehmen.
 *
 * Ohne diese Grenze liefe der Versand für jeden Empfänger einzeln in denselben
 * Zustellfehler. Der Newsletter stünde längst als versendet da, und die
 * Redaktion erführe erst aus dem Fehlerbericht, dass nichts angekommen ist -
 * nachschicken lässt er sich dann nicht mehr.
 */
class NewsletterAttachmentsTooLargeException extends RuntimeException
{
    public function __construct(int $totalBytes, int $limitBytes)
    {
        parent::__construct(sprintf(
            'Die angehängten Dateien sind zusammen %d MB groß, erlaubt sind %d MB. '
            . 'Stelle große Dateien auf "In der Mail verlinken" um.',
            (int) ceil($totalBytes / 1048576),
            (int) floor($limitBytes / 1048576)
        ));
    }
}
