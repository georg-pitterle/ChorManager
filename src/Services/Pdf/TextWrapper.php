<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class TextWrapper
{
    /**
     * Greedy Wort-Umbruch. Ein Wort, das breiter als $maxWidth ist, steht allein
     * in seiner Zeile (kein Zeichen-Splitting). Liefert immer mindestens [''].
     *
     * @param callable(string,float):float $stringWidth
     * @return string[]
     */
    public static function wrap(string $text, float $maxWidth, float $fontSize, callable $stringWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === [] || $words === ['']) {
            return [''];
        }

        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $stringWidth($candidate, $fontSize) >= $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}
