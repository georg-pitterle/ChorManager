<?php

declare(strict_types=1);

namespace App\Services\Pdf;

/**
 * Minimale Zeichenfläche für das Finanz-PDF. Kapselt tc-lib-pdf, damit die
 * Umbruch-/Übertrag-Logik rein und ohne PDF-Engine testbar bleibt.
 * Koordinaten in Punkten, Y wächst nach unten, contentTop < contentBottom.
 */
interface PdfCanvas
{
    public function addPage(): void;

    public function contentTop(): float;

    public function contentBottom(): float;

    public function contentLeft(): float;

    public function contentWidth(): float;

    public function stringWidth(string $text, float $fontSize): float;

    /** @param string $style '' für normal, 'B' für fett */
    public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void;

    public function line(float $x1, float $y1, float $x2, float $y2): void;

    /** Zeichnet ein Rasterbild (rohe Bytes, z. B. PNG/JPEG) an Position x,y mit Breite/Höhe in Punkten. */
    public function image(string $imageData, float $x, float $y, float $width, float $height): void;

    public function pageCount(): int;

    /** @return string rohe PDF-Bytes, beginnend mit "%PDF" */
    public function output(): string;
}
