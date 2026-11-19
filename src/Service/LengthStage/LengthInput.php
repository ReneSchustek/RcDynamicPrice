<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Service\LengthStage;

/**
 * Liest die Längen aus der Eingabe des Kunden, in Millimetern.
 *
 * Dieselben Regeln wie im Browser (`util/parse-length.js` und `splitLengths()` im Längenschalter): Mit Einheit ist eine
 * Angabe eindeutig („4,2 m", „420 cm", „4200 mm"); ohne Einheit gilt eine Zahl mit Komma oder Punkt als Meter, eine
 * ganze Zahl als Millimeter. Eine ganze Zahl unter 10 ohne Einheit ist nicht eindeutig. Mehrere Längen trennt der Kunde
 * mit Semikolon, Leerzeichen oder Komma; das Komma ist zugleich Dezimalkomma und gilt als Trenner nur mit Leerzeichen
 * danach, direkt hinter einer Einheit oder mit drei und mehr Ziffern davor.
 */
final class LengthInput
{
    private const MILLIMETRES_PER_UNIT = ['mm' => 1, 'cm' => 10, 'm' => 1000];

    /**
     * @return list<int>|null die Längen; eine leere Liste bei leerer Eingabe, `null`, wenn eine Angabe nicht lesbar ist
     */
    public static function parse(string $value): ?array
    {
        $lengths = [];
        foreach (self::split($value) as $part) {
            $length = self::parseOne($part);
            if ($length === null) {
                return null;
            }
            $lengths[] = $length;
        }

        return $lengths;
    }

    /**
     * @return list<string>
     */
    public static function split(string $value): array
    {
        $text = trim($value);
        $text = (string) preg_replace('/(\d{3,}),(?=\d)/', '$1;', $text);
        $text = (string) preg_replace('/(mm|cm|m),/i', '$1;', $text);
        $text = (string) preg_replace('/,\s+/', ';', $text);

        $tokens = [];
        foreach (explode(';', $text) as $index => $part) {
            foreach (preg_split('/\s+/', trim($part)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $last = array_key_last($tokens);
                if (preg_match('/^(mm|cm|m)$/i', $word) === 1 && $last !== null && $tokens[$last]['part'] === $index && preg_match('/\d$/', $tokens[$last]['text']) === 1) {
                    $tokens[$last]['text'] .= ' ' . $word;
                } else {
                    $tokens[] = ['part' => $index, 'text' => $word];
                }
            }
        }

        return array_column($tokens, 'text');
    }

    private static function parseOne(string $text): ?int
    {
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(mm|cm|m)?$/i', trim($text), $match) !== 1) {
            return null;
        }

        $number = (float) str_replace(',', '.', $match[1]);
        $unit = mb_strtolower($match[2] ?? '');
        $hasDecimals = preg_match('/[.,]/', $match[1]) === 1;
        if ($number <= 0.0 || ($unit === '' && !$hasDecimals && $number < 10)) {
            return null;
        }

        $millimetres = (int) round($number * ($unit === '' ? ($hasDecimals ? 1000 : 1) : self::MILLIMETRES_PER_UNIT[$unit]));

        return $millimetres > 0 ? $millimetres : null;
    }
}
