<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Com\Tecnick\Pdf\Tcpdf;

/**
 * tc-lib-pdf-Adapter. A4 Querformat, feste Ränder. Übersetzt die neutrale
 * PdfCanvas-Geometrie (Y nach unten, Punkte) auf die tc-lib-pdf-API.
 *
 * tc-lib-pdf misst intern in Punkten von oben nach unten (Unit 'pt' hat einen
 * Skalierungsfaktor von 1.0), sobald die Y-Konvertierung über die öffentlichen
 * *Unit()-Helfer läuft — das entspricht exakt der PdfCanvas-Konvention.
 */
final class TcLibPdfCanvas implements PdfCanvas
{
    private const MARGIN = 40.0;          // Punkte
    private const PAGE_WIDTH = 841.89;    // A4 Breite in pt (Querformat)
    private const PAGE_HEIGHT = 595.28;   // A4 Höhe in pt (Querformat)
    private const FOOTER_RESERVE = 24.0;  // Platz unten für Seitenfuß
    private const FONT_FAMILY = 'dejavusans';

    private Tcpdf $pdf;
    private int $pages = 0;

    public function __construct()
    {
        // K_PATH_FONTS wird von tc-lib-pdf-font erwartet, um die im Repo
        // mitgelieferten Font-Metadaten unter resources/pdf-fonts/ zu finden
        // (kein Netz-Fetch bei composer install/update nötig).
        if (!\defined('K_PATH_FONTS')) {
            \define('K_PATH_FONTS', self::resolveFontsPath());
        }

        $this->pdf = new Tcpdf(unit: 'pt', isunicode: true, subsetfont: false, compress: true);
        // Kein automatischer Seitenfuß der Bibliothek: FOOTER_RESERVE bleibt
        // für den von den aufrufenden Tasks selbst gezeichneten Fuß reserviert.
        $this->pdf->enableDefaultPageContent(false);
    }

    /**
     * Ermittelt den Pfad zu den im Repo mitgelieferten Font-Metadaten
     * (resources/pdf-fonts/). Bricht mit einer klaren Meldung ab, statt eine
     * leere K_PATH_FONTS-Konstante zu definieren, die erst später als
     * kryptische FontException von tc-lib-pdf-font auftauchen würde.
     */
    private static function resolveFontsPath(): string
    {
        $expected = __DIR__ . '/../../../resources/pdf-fonts';
        $fontsPath = \realpath($expected);

        if ($fontsPath === false) {
            throw new \RuntimeException(
                'PDF-Fontverzeichnis nicht gefunden. Erwartet unter "resources/pdf-fonts/" '
                . '(aufgelöst von "' . $expected . '"). Bitte prüfen, ob das Verzeichnis im '
                . 'Repository vorhanden und nicht versehentlich gelöscht wurde.'
            );
        }

        return $fontsPath;
    }

    public function addPage(): void
    {
        // Ohne explizites 'format'/'orientation' würde tc-lib-pdf-page bei der
        // ersten Seite auf A4 Hochformat zurückfallen (Default in
        // Settings::sanitizePageFormat()); deshalb hier auf jeder Seite fest
        // A4 Querformat anfordern, statt auf das Klonen der Vorseite zu setzen.
        $this->pdf->addPage(['format' => 'A4', 'orientation' => 'L']);
        $this->pages++;
    }

    public function contentTop(): float
    {
        return self::MARGIN;
    }

    public function contentBottom(): float
    {
        return self::PAGE_HEIGHT - self::MARGIN - self::FOOTER_RESERVE;
    }

    public function contentLeft(): float
    {
        return self::MARGIN;
    }

    public function contentWidth(): float
    {
        return self::PAGE_WIDTH - (2 * self::MARGIN);
    }

    public function stringWidth(string $text, float $fontSize): float
    {
        // Font nur temporär auf den Stack legen: reine Vermessung ohne
        // sichtbare Seiteninhalte, deshalb kein page->addContent() und
        // anschließend wieder vom Stack entfernen.
        $this->pdf->font->insert($this->pdf->pon, self::FONT_FAMILY, '', $fontSize);
        $ordArr = $this->pdf->uniconv->strToOrdArr($text);
        $width = $this->pdf->font->getOrdArrWidth($ordArr);
        $this->pdf->font->popLastFont();

        return $width;
    }

    /** @param string $style '' für normal, 'B' für fett */
    public function text(float $x, float $y, string $text, float $fontSize, string $style = ''): void
    {
        $font = $this->pdf->font->insert($this->pdf->pon, self::FONT_FAMILY, $style, $fontSize);
        $this->pdf->page->addContent($font['out']);
        $this->pdf->addTextCell(
            txt: $text,
            posx: $x,
            posy: $y,
            valign: 'T',
            halign: 'L',
            drawcell: false,
        );
        // Symmetrisch zu stringWidth(): Font wieder vom Stack entfernen,
        // damit dieser bei vielen text()-Aufrufen (z. B. langen Auszügen)
        // nicht unbegrenzt wächst. Der Inhalt wurde bereits über
        // page->addContent() in den Seiteninhalt geschrieben.
        $this->pdf->font->popLastFont();
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $content = $this->pdf->graph->getLine($x1, $y1, $x2, $y2);
        $this->pdf->page->addContent($content);
    }

    /**
     * Zeichnet ein Rasterbild (rohe Bytes, z. B. PNG/JPEG) an Position x,y mit
     * Breite/Höhe in Punkten. (x,y) ist die linke obere Ecke der Bild-Box, wie
     * bei text(). tc-lib-pdf-image nimmt rohe Bild-Bytes direkt über den
     * '@'-Präfix entgegen (kein Umweg über eine temporäre Datei nötig);
     * getSetImage() rechnet die von oben gezählte Y-Koordinate intern auf das
     * PDF-eigene Koordinatensystem (Ursprung unten links) um.
     */
    public function image(string $imageData, float $x, float $y, float $width, float $height): void
    {
        $imageId = $this->pdf->image->add('@' . $imageData);
        $pageHeight = $this->pdf->page->getPage()['height'];
        $content = $this->pdf->image->getSetImage($imageId, $x, $y, $width, $height, $pageHeight);
        $this->pdf->page->addContent($content);
    }

    public function pageCount(): int
    {
        return $this->pages;
    }

    public function output(): string
    {
        return $this->pdf->getOutPDFString();
    }
}
